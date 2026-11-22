<?php

namespace Tests\Feature;

use App\Models\Borc;
use App\Models\DefterAyi;
use App\Models\DefterDegisikligi;
use App\Models\DuzenliGider;
use App\Models\FiyatGecmisi;
use App\Models\Hesap;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Defter: kapanmış ay mühürlenir ve değişmez; kapanıştan sonra yapılan ekleme / düzeltme / silme "sonradan değişiklikler"de
 * (kim, ne zaman, eski → yeni) ayrı listelenir, ay toplamlarına katılmaz; salt okunur; hane izolasyonu.
 */
class DefterTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        config(['defter.izleme' => true]);
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->ben = User::factory()->create(['ad' => 'Süreyya', 'soyad' => 'Test']);
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
    }

    private function gider(string $ad, float $tutar, string $tarih = '2026-11-12'): DuzenliGider
    {
        return DuzenliGider::create([
            'hane_id' => $this->ben->hane_id, 'aciklama' => $ad, 'tutar' => $tutar, 'tarih' => $tarih,
            'donem' => substr($tarih, 0, 7), 'olusturan_id' => $this->ben->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function defter(string $donem): array
    {
        return $this->getJson('/api/v1/aile/defter?donem='.$donem)->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<int, string>
     */
    private function satirlar(array $d): array
    {
        return collect($d['defter']['gruplar'])->flatMap(fn (array $g): array => $g['satirlar'])->pluck('ad')->all();
    }

    public function test_acik_ay_canli_hesaplanir_ve_degisiklikler_izlenmez(): void
    {
        $g = $this->gider('Market', 100);
        $d = $this->defter('2026-11');
        $this->assertFalse($d['kapali']);
        $this->assertContains('Market', $this->satirlar($d));
        $g->update(['tutar' => 150]);
        $g->delete();
        $this->assertSame(0, DefterDegisikligi::count());
        $this->assertSame(0, DefterAyi::count());
    }

    public function test_kapanmis_ay_muhurlenir_ve_sonradan_eklenen_toplama_girmez(): void
    {
        $this->gider('Market', 100);
        Carbon::setTestNow('2026-12-03 09:00:00');
        $once = $this->defter('2026-11');
        $this->assertTrue($once['kapali']);
        $this->assertEquals(100, $once['defter']['ozet']['gider']);

        Carbon::setTestNow('2026-12-04 11:30:00');
        $this->gider('Pazar', 520, '2026-11-29');
        $sonra = $this->defter('2026-11');
        $this->assertEquals(100, $sonra['defter']['ozet']['gider'], 'mühürlü ay değişmez');
        $this->assertNotContains('Pazar', $this->satirlar($sonra));
        $this->assertCount(1, $sonra['degisiklikler']);
        $this->assertSame('eklendi', $sonra['degisiklikler'][0]['tip']);
        $this->assertSame('Pazar', $sonra['degisiklikler'][0]['baslik']);
        $this->assertEquals(520, $sonra['degisiklikler'][0]['yeni_tutar']);
        $this->assertSame('Süreyya Test', $sonra['degisiklikler'][0]['yapan_ad']);
        $this->assertStringStartsWith('2026-12-04T11:30', $sonra['degisiklikler'][0]['yapildi_at']);
    }

    public function test_sonradan_duzeltme_ve_silme_eski_yeni_degerle_kaydedilir_defter_ayni_kalir(): void
    {
        $elektrik = $this->gider('Elektrik', 1284.50);
        $terzi = $this->gider('Terzi', 600);
        Carbon::setTestNow('2026-12-03 21:00:00');
        $elektrik->update(['tutar' => 1248.50]);
        Carbon::setTestNow('2026-12-04 08:40:00');
        $terzi->delete();

        $d = $this->defter('2026-11');
        $this->assertEquals(1884.50, $d['defter']['ozet']['gider']);
        $this->assertEqualsCanonicalizing(['Elektrik', 'Terzi'], $this->satirlar($d));
        $tipler = collect($d['degisiklikler'])->keyBy('baslik');
        $this->assertSame('duzeltildi', $tipler['Elektrik']['tip']);
        $this->assertEquals(1284.50, $tipler['Elektrik']['eski_tutar']);
        $this->assertEquals(1248.50, $tipler['Elektrik']['yeni_tutar']);
        $this->assertSame('silindi', $tipler['Terzi']['tip']);
        $this->assertEquals(600, $tipler['Terzi']['eski_tutar']);
        $this->assertNull($tipler['Terzi']['yeni_tutar']);
    }

    public function test_tutari_degismeyen_guncelleme_kayit_yazmaz(): void
    {
        $g = $this->gider('Market', 100);
        Carbon::setTestNow('2026-12-03 09:00:00');
        $g->update(['not' => 'x']);
        $this->assertSame(0, DefterDegisikligi::count());
    }

    public function test_borc_kaydi_defterde_gorunur(): void
    {
        $borc = Borc::create(['hane_id' => $this->ben->hane_id, 'yon' => Borc::ALACAK, 'kisi' => 'Mehmet', 'tutar' => 5000, 'tarih' => '2026-11-03', 'olusturan_id' => $this->ben->id]);
        $borc->odemeler()->create(['hane_id' => $this->ben->hane_id, 'tutar' => 2000, 'tarih' => '2026-11-18']);
        Carbon::setTestNow('2026-12-03 09:00:00');
        $d = $this->defter('2026-11');
        $this->assertEquals(3000, $d['defter']['borclar']['alacak']);
        $this->assertSame('Mehmet', $d['defter']['borclar']['kayitlar'][0]['kisi']);
    }

    public function test_yazma_ucu_yok_ve_gelecek_ay_reddedilir(): void
    {
        $this->postJson('/api/v1/aile/defter', [])->assertStatus(405);
        $this->putJson('/api/v1/aile/defter', [])->assertStatus(405);
        $this->deleteJson('/api/v1/aile/defter')->assertStatus(405);
        $this->getJson('/api/v1/aile/defter?donem=2027-03')->assertStatus(422);
        $this->getJson('/api/v1/aile/defter?donem=abc')->assertStatus(422);
    }

    public function test_baska_hane_gormez(): void
    {
        $this->gider('Gizli market', 777);
        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->defter('2026-11');
        $this->gider('Gizli sonradan', 55, '2026-11-25');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $d = $this->defter('2026-11');
        $this->assertNotContains('Gizli market', $this->satirlar($d));
        $this->assertSame([], $d['degisiklikler']);
        $this->assertEquals(0, $d['defter']['ozet']['gider']);
    }

    public function test_kapat_komutu_gecen_ayi_muhurler(): void
    {
        $this->gider('Market', 100);
        Carbon::setTestNow('2026-12-01 00:20:00');
        $this->artisan('defter:kapat')->assertSuccessful();
        $this->assertDatabaseHas('defter_aylari', ['hane_id' => $this->ben->hane_id, 'donem' => '2026-11']);
        $this->artisan('defter:kapat')->assertSuccessful();
        $this->assertSame(1, DefterAyi::where('donem', '2026-11')->count());
    }

    public function test_kalemler_adet_ve_deger_degisimini_islem_ve_fiyat_hareketine_ayirir(): void
    {
        $hesap = Hesap::create(['user_id' => $this->ben->id, 'ad' => 'Yatırım', 'tur' => 'banka']);
        $thy = $this->varlik('THYAO', 'hisse', 78.40);
        foreach ([['2026-10-05', 70.0], ['2026-10-31', 70.0], ['2026-11-30', 78.4]] as [$tarih, $kapanis]) {
            FiyatGecmisi::create(['varlik_id' => $thy->id, 'tarih' => $tarih, 'kapanis' => $kapanis]);
        }
        $hesap->islemler()->create(['user_id' => $this->ben->id, 'varlik_id' => $thy->id, 'tur' => 'alis', 'miktar' => 1500, 'birim_fiyat' => 60, 'tarih' => '2026-10-05']);
        $hesap->islemler()->create(['user_id' => $this->ben->id, 'varlik_id' => $thy->id, 'tur' => 'alis', 'miktar' => 75, 'birim_fiyat' => 75, 'tarih' => '2026-11-12']);
        $hesap->islemler()->create(['user_id' => $this->ben->id, 'varlik_id' => $thy->id, 'tur' => 'temettu', 'miktar' => 270, 'komisyon' => 30, 'tarih' => '2026-11-18', 'bag_turu' => 'temettu']);
        Carbon::setTestNow('2026-12-03 09:00:00');

        $d = $this->defter('2026-11');
        $k = collect($d['defter']['yatirim']['kalemler'])->firstWhere('kod', 'THYAO');
        $this->assertEquals(1500, $k['ilk']);
        $this->assertEquals(1575, $k['son']);
        $this->assertEquals(70, $k['fiyat_ilk']);
        $this->assertEquals(78.4, $k['fiyat_son']);
        $this->assertEquals(105000, $k['deger_ilk']);
        $this->assertEquals(123480, $k['deger_son']);
        $this->assertCount(1, $k['islemler']);
        $this->assertEquals(75, $k['islemler'][0]['adet']);
        $this->assertEquals(5625, $k['net_islem']);
        $this->assertEquals(12855, $k['fiyat_etkisi']);
        $this->assertEquals(270, $k['temettu']);
        $this->assertEquals(270, $d['defter']['temettu']['toplam']);
        $this->assertSame('THYAO temettüsü', $d['defter']['temettu']['satirlar'][0]['ad']);
    }
}
