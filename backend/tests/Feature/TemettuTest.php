<?php

namespace Tests\Feature;

use App\Enums\IslemTuru;
use App\Models\FiyatGecmisi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Aile\HaneYonetimi;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Temettü (hisse satırı + aynı hesaba nakit, eşleşik) ve bedelsiz sermaye artırımı (adet artar, maliyet aynı).
 */
class TemettuTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private Varlik $hisse;

    private Varlik $nakit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Yatırım']);
        $this->hisse = $this->varlik('THYAO', 'hisse', 12);
        $this->nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        $this->actingAs($this->ben);
    }

    private function alis(float $miktar, ?float $fiyat, string $tarih = '2026-10-01', ?Varlik $varlik = null): Islem
    {
        return Islem::create([
            'user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => ($varlik ?? $this->hisse)->id,
            'tur' => $fiyat === null ? IslemTuru::Mevcut : IslemTuru::Alis, 'miktar' => $miktar, 'birim_fiyat' => $fiyat,
            'komisyon' => 0, 'tarih' => $tarih,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ek
     */
    private function gir(string $tur, float $miktar, string $tarih = '2026-11-01', array $ek = []): TestResponse
    {
        return $this->postJson('/api/v1/islemler', [
            'hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse->id, 'tur' => $tur, 'miktar' => $miktar,
            'tarih' => $tarih, ...$ek,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function poz(Varlik $varlik): ?array
    {
        return collect(app(PortfoyHesabi::class)->hesapla($this->ben, $this->hesap->id)['pozisyonlar'])->firstWhere('varlik.id', $varlik->id);
    }

    public function test_temettu_hisseye_yazilir_ayni_hesaba_nakit_eklenir_adet_ve_maliyet_degismez(): void
    {
        $this->alis(100, 10);

        $this->gir('temettu', 85, ek: ['komisyon' => 15])->assertCreated()
            ->assertJsonPath('islem.tur', 'temettu')
            ->assertJsonPath('islem.tutar', 85)
            ->assertJsonPath('islem.stopaj', 15)
            ->assertJsonPath('islem.brut', 100)
            ->assertJsonPath('islem.bag_turu', 'temettu');

        $kayitlar = Islem::whereNotNull('aktarim_kodu')->get();
        $this->assertCount(2, $kayitlar);
        $this->assertCount(1, $kayitlar->pluck('aktarim_kodu')->unique());
        $this->assertSame(['temettu'], $kayitlar->pluck('bag_turu')->unique()->values()->all());
        $nakitKaydi = $kayitlar->firstWhere('varlik_id', $this->nakit->id);
        $this->assertSame(IslemTuru::Alis, $nakitKaydi->tur);
        $this->assertEqualsWithDelta(85, $nakitKaydi->miktar, 1e-9);
        $this->assertStringContainsString('THYAO temettüsü', $nakitKaydi->not);

        $hisse = $this->poz($this->hisse);
        $this->assertEqualsWithDelta(100, $hisse['miktar'], 1e-9);
        $this->assertEqualsWithDelta(1000, $hisse['maliyet'], 0.01);
        $this->assertEqualsWithDelta(85, $hisse['temettu'], 0.01);
        $this->assertEqualsWithDelta(85, $this->poz($this->nakit)['deger'], 0.01);

        $ozet = app(PortfoyHesabi::class)->hesapla($this->ben);
        $this->assertEqualsWithDelta(85, $ozet['toplam']['temettu'], 0.01);
        $this->assertEqualsWithDelta(1200 + 85, $ozet['toplam']['deger'], 0.01); // 100 × 12 + nakit
    }

    public function test_satilmis_hissenin_temettusu_toplamda_kalir(): void
    {
        $this->alis(100, 10);
        $this->gir('temettu', 50)->assertCreated();
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse->id, 'tur' => IslemTuru::Satis, 'miktar' => 100, 'birim_fiyat' => 12, 'komisyon' => 0, 'tarih' => '2026-11-05']);

        $this->assertNull($this->poz($this->hisse)); // kapalı pozisyon listede yok
        $this->assertEqualsWithDelta(50, app(PortfoyHesabi::class)->hesapla($this->ben)['toplam']['temettu'], 0.01);
    }

    public function test_elde_hisse_yokken_ya_da_hisse_olmayan_varlikta_temettu_ve_bedelsiz_girilemez(): void
    {
        $this->gir('temettu', 50)->assertJsonValidationErrors('tarih');
        $this->gir('bedelsiz', 100)->assertJsonValidationErrors('tarih');

        $this->alis(100, 10, '2026-10-01');
        // Alıştan önceki tarihe de girilemez
        $this->gir('temettu', 50, '2026-09-15')->assertJsonValidationErrors('tarih');
        $this->assertSame(0, Islem::where('tur', 'temettu')->count());

        $altin = $this->varlik('GRAM', 'altin', 3000);
        $this->alis(10, 3000, varlik: $altin);
        $this->postJson('/api/v1/islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'tur' => 'temettu', 'miktar' => 50, 'tarih' => '2026-11-01'])
            ->assertJsonValidationErrors('tur');
        $this->postJson('/api/v1/islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'tur' => 'bedelsiz', 'miktar' => 50, 'tarih' => '2026-11-01'])
            ->assertJsonValidationErrors('tur');
    }

    public function test_temettu_birlikte_silinir_duzenlenmez_dahil_birlikte_degisir(): void
    {
        $this->alis(100, 10);
        $id = $this->gir('temettu', 85)->assertCreated()->json('islem.id');

        $this->putJson("/api/v1/islemler/{$id}", ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse->id, 'tur' => 'temettu', 'miktar' => 90, 'tarih' => '2026-11-01'])
            ->assertJsonValidationErrors('islem');

        $this->putJson("/api/v1/islemler/{$id}/dahil", ['dahil' => false])->assertOk();
        $this->assertSame(2, Islem::where('dahil', false)->count());
        $this->putJson("/api/v1/islemler/{$id}/dahil", ['dahil' => true])->assertOk();

        // Nakit kaydından silmek de ikisini siler
        $nakitId = Islem::where('varlik_id', $this->nakit->id)->value('id');
        $this->deleteJson("/api/v1/islemler/{$nakitId}")->assertOk()->assertJsonPath('message', 'Temettü ve hesaba eklenen nakit silindi.');
        $this->assertSame(0, Islem::whereNotNull('aktarim_kodu')->count());
        $this->assertSame(1, Islem::count()); // yalnız alış kaldı
    }

    public function test_temettu_nakdi_sonradan_cekildiyse_temettu_silinemez(): void
    {
        $this->alis(100, 10);
        $id = $this->gir('temettu', 85)->assertCreated()->json('islem.id');
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->nakit->id, 'tur' => IslemTuru::Satis, 'miktar' => 80, 'birim_fiyat' => 1, 'komisyon' => 0, 'tarih' => '2026-11-10']);

        $this->deleteJson("/api/v1/islemler/{$id}")->assertJsonValidationErrors('islem');
        $this->assertSame(2, Islem::whereNotNull('aktarim_kodu')->count());
    }

    public function test_hisseyi_satin_alan_alisi_silmek_temettuyu_elde_hissesiz_birakiyorsa_reddedilir(): void
    {
        $alis = $this->alis(100, 10);
        $this->gir('temettu', 85)->assertCreated();

        $this->deleteJson("/api/v1/islemler/{$alis->id}")->assertJsonValidationErrors('islem');
        $this->assertStringContainsString('temettü kaydı elde hisse olmadan kalır', $this->deleteJson("/api/v1/islemler/{$alis->id}")->json('errors.islem.0'));
    }

    public function test_performans_serisinde_temettu_gerceklesen_getiriye_sayilir(): void
    {
        $this->alis(100, 10, '2026-11-01');
        FiyatGecmisi::insert([
            ['varlik_id' => $this->hisse->id, 'tarih' => '2026-11-01', 'kapanis' => 10],
            ['varlik_id' => $this->hisse->id, 'tarih' => '2026-11-03', 'kapanis' => 9.5], // temettü sonrası düşüş
        ]);
        $this->gir('temettu', 50, '2026-11-03')->assertCreated();

        $g = collect(app(PortfoyHesabi::class)->gecmis($this->ben, null, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-04')))->keyBy('tarih');

        $this->assertEqualsWithDelta(0, $g['2026-11-02']['gerceklesen'], 0.01);
        $this->assertEqualsWithDelta(50, $g['2026-11-03']['gerceklesen'], 0.01);
        $this->assertEqualsWithDelta(-50, $g['2026-11-03']['kz'], 0.01); // 100 × (9,5 − 10)
        $this->assertEqualsWithDelta(950 + 50, $g['2026-11-03']['deger'], 0.01); // hisse + nakit
        // Dönem K/Z (kz + gerçekleşen) temettüyle sıfırlanır: fiyat düşüşü temettüyle karşılandı
        $this->assertEqualsWithDelta(0, $g['2026-11-03']['kz'] + $g['2026-11-03']['gerceklesen'], 0.01);
    }

    public function test_bedelsiz_adedi_oranla_artirir_toplam_maliyet_ayni_kalir(): void
    {
        $this->alis(100, 10, '2026-10-01');
        $this->alis(20, null, '2026-10-02'); // maliyeti bilinmeyen 20 adet

        $this->gir('bedelsiz', 50)->assertCreated()
            ->assertJsonPath('islem.oran_yuzde', 50)
            ->assertJsonPath('islem.tutar', null);

        $p = $this->poz($this->hisse);
        $this->assertEqualsWithDelta(180, $p['miktar'], 1e-9);
        $this->assertEqualsWithDelta(30, $p['maliyeti_bilinmeyen_miktar'], 1e-9);
        $this->assertEqualsWithDelta(1000, $p['maliyet'], 0.01);
        $this->assertEqualsWithDelta(1000 / 150, $p['ortalama_maliyet'], 1e-4);
        $this->assertSame(0, Islem::where('varlik_id', $this->nakit->id)->count()); // bedelsizde nakit yok

        // Bedelsizle gelen hisse satılabilir; bedelsizi silmek bu satışı bozacağı için reddedilir
        $this->postJson('/api/v1/islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse->id, 'tur' => 'satis', 'miktar' => 180, 'birim_fiyat' => 12, 'tarih' => '2026-11-10'])
            ->assertCreated();
        $bedelsizId = Islem::where('tur', 'bedelsiz')->value('id');
        $this->deleteJson("/api/v1/islemler/{$bedelsizId}")->assertJsonValidationErrors('islem');
    }

    public function test_bedelsiz_duzenlenebilir_ve_oran_siniri(): void
    {
        $this->alis(100, 10);
        $id = $this->gir('bedelsiz', 100)->assertCreated()->json('islem.id');
        $this->assertEqualsWithDelta(200, $this->poz($this->hisse)['miktar'], 1e-9);

        $this->putJson("/api/v1/islemler/{$id}", ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse->id, 'tur' => 'bedelsiz', 'miktar' => 25, 'tarih' => '2026-11-01'])
            ->assertOk();
        $this->assertEqualsWithDelta(125, $this->poz($this->hisse)['miktar'], 1e-9);

        $this->gir('bedelsiz', 20000)->assertJsonValidationErrors('miktar');
        // Var olan alış temettüye çevrilemez (eşleşik nakit yazılmaz)
        $alisId = Islem::where('tur', 'alis')->value('id');
        $this->putJson("/api/v1/islemler/{$alisId}", ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse->id, 'tur' => 'temettu', 'miktar' => 50, 'tarih' => '2026-11-01'])
            ->assertJsonValidationErrors('tur');
    }

    public function test_baska_hanenin_hesabina_temettu_girilemez_temettusu_silinemez(): void
    {
        $this->alis(100, 10);
        $id = $this->gir('temettu', 85)->assertCreated()->json('islem.id');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->gir('temettu', 10)->assertJsonValidationErrors('hesap_id');
        $this->deleteJson("/api/v1/islemler/{$id}")->assertForbidden();
        $this->assertSame(2, Islem::whereNotNull('aktarim_kodu')->count());
    }
}
