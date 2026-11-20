<?php

namespace Tests\Feature;

use App\Models\DuzenliGider;
use App\Models\HaneUyesi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\SilinenKaydi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Silinenler: silinen her kayıt arşivlenir (ne, kim yaptı / ne zaman, kim sildi / ne zaman); bağlı kayıtlar ana kaydın
 * `alt` alanında; iç içe silme çift kayıt yazmaz; salt okunur; hane izolasyonu.
 */
class SilinenlerTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create(['ad' => 'Süreyya', 'soyad' => 'Test']);
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
    }

    /**
     * @param  array<string, mixed>  $alan
     * @return array<string, mixed>
     */
    private function gider(array $alan = []): array
    {
        return ['aciklama' => 'Simit', 'tutar' => 40, 'tarih' => '2026-11-12', 'donem' => '2026-11', 'odeme_yeri' => 'ev', ...$alan];
    }

    private function evParasi(float $tutar = 500): void
    {
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => $tutar, 'tarih' => '2026-11-10'])->assertCreated();
    }

    public function test_silinen_gider_kimin_ne_zaman_yaptigi_ve_sildigiyle_arsivlenir(): void
    {
        $this->evParasi();
        $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider())->assertCreated()->json('gider.id');
        Carbon::setTestNow('2026-11-14 09:30:00');
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();
        $this->assertDatabaseMissing('duzenli_giderler', ['id' => $id]);

        $liste = $this->getJson('/api/v1/aile/silinenler')->assertOk()->assertJsonPath('toplam', 1);
        $k = $liste->json('kayitlar.0');
        $this->assertSame('gider', $k['tur']);
        $this->assertSame('Simit · 12.11.2026', $k['baslik']);
        $this->assertEquals(40, $k['tutar']);
        $this->assertSame('Süreyya Test', $k['olusturan_ad']);
        $this->assertSame('Süreyya Test', $k['silen_ad']);
        $this->assertStringStartsWith('2026-11-13', $k['olusturuldu_at']);
        $this->assertStringStartsWith('2026-11-14T09:30', $k['silindi_at']);

        $ayrinti = $this->getJson('/api/v1/aile/silinenler/'.$k['id'])->assertOk();
        $this->assertSame('Simit', $ayrinti->json('kayit.icerik.aciklama'));
    }

    public function test_banka_harcamasi_tek_kayit_yazar_hesaptaki_satis_ayri_kayit_olmaz(): void
    {
        $hesap = Hesap::factory()->create(['user_id' => $this->ben->id]);
        $nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $hesap->id, 'varlik_id' => $nakit->id, 'tur' => 'alis', 'miktar' => 1000, 'birim_fiyat' => 1, 'komisyon' => 0, 'tarih' => '2026-11-01']);
        $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'banka', 'hesap_id' => $hesap->id, 'tutar' => 300]))->assertCreated()->json('gider.id');
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();

        $this->assertSame(1, SilinenKaydi::count());
        $this->assertSame('gider', SilinenKaydi::first()->tur);
    }

    public function test_borc_odemeleriyle_ve_odeme_ayri_arsivlenir(): void
    {
        $borc = $this->postJson('/api/v1/aile/borclar', ['yon' => 'borc', 'kisi' => 'Ahmet', 'tutar' => 1000, 'tarih' => '2026-11-01'])->assertCreated()->json('borc.id');
        $odeme = $this->postJson("/api/v1/aile/borclar/{$borc}/odemeleri", ['tutar' => 100, 'tarih' => '2026-11-05'])->assertCreated()->json('borc.odemeler.0.id');
        $this->postJson("/api/v1/aile/borclar/{$borc}/odemeleri", ['tutar' => 50, 'tarih' => '2026-11-06'])->assertCreated();
        $this->deleteJson("/api/v1/aile/borclar/{$borc}/odemeleri/{$odeme}")->assertOk();
        $this->deleteJson("/api/v1/aile/borclar/{$borc}")->assertOk();

        $this->assertSame(['borc', 'borc_odemesi'], SilinenKaydi::orderBy('id')->pluck('tur')->sort()->values()->all());
        $kayit = SilinenKaydi::where('tur', 'borc')->first();
        $this->assertSame('Ahmet · borç', $kayit->baslik);
        $this->assertCount(1, $kayit->alt);
        $this->assertEquals(50, $kayit->alt[0]['tutar']);
    }

    public function test_hesap_silinince_islemleri_alt_alanda_tek_kayit(): void
    {
        $hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Eski hesap']);
        $nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        foreach ([1000, 500] as $i => $miktar) {
            Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $hesap->id, 'varlik_id' => $nakit->id, 'tur' => 'alis', 'miktar' => $miktar, 'birim_fiyat' => 1, 'komisyon' => 0, 'tarih' => '2026-11-0'.($i + 1)]);
        }
        $this->deleteJson("/api/v1/hesaplar/{$hesap->id}")->assertOk();

        $this->assertSame(1, SilinenKaydi::count());
        $k = SilinenKaydi::first();
        $this->assertSame('hesap', $k->tur);
        $this->assertSame('Eski hesap', $k->baslik);
        $this->assertCount(2, $k->alt);
    }

    public function test_portfoy_islemi_silinince_arsivlenir(): void
    {
        $hesap = Hesap::factory()->create(['user_id' => $this->ben->id]);
        $nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        $islem = Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $hesap->id, 'varlik_id' => $nakit->id, 'tur' => 'alis', 'miktar' => 700, 'birim_fiyat' => 1, 'komisyon' => 0, 'tarih' => '2026-11-01']);
        $this->deleteJson("/api/v1/islemler/{$islem->id}")->assertOk();

        $k = SilinenKaydi::firstOrFail();
        $this->assertSame('portfoy_islemi', $k->tur);
        $this->assertSame('Süreyya Test', $k->silen_ad);
    }

    public function test_tasarruf_silinince_tek_kayit_alis_altta(): void
    {
        $hesap = Hesap::factory()->create(['user_id' => $this->ben->id]);
        $nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        $id = $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $hesap->id, 'varlik_id' => $nakit->id, 'miktar' => 250, 'tarih' => '2026-11-10'])->assertCreated()->json('tasarruf.id');
        $this->deleteJson("/api/v1/aile/tasarruflar/{$id}")->assertOk();

        $this->assertSame(1, SilinenKaydi::count());
        $k = SilinenKaydi::first();
        $this->assertSame('tasarruf', $k->tur);
        $this->assertCount(1, $k->alt);
    }

    public function test_ekstreden_gelen_satir_dista_birakilinca_arsivlenmez_elle_satir_silinince_arsivlenir(): void
    {
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->assertCreated()->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'Bonus', 'kesim_gunu' => 10, 'son_odeme_gunu' => 20])->assertCreated()->json('kart.id');
        $islem = $this->postJson('/api/v1/aile/kart-islemleri', ['kart_id' => $kart, 'islem_tarihi' => '2026-11-05', 'aciklama' => 'MIGROS', 'tutar' => 500, 'tur' => 'harcama'])->assertCreated()->json('islem.id');
        $this->deleteJson("/api/v1/aile/kart-islemleri/{$islem}")->assertOk();

        $k = SilinenKaydi::firstOrFail();
        $this->assertSame('kart_islemi', $k->tur);
        $this->assertSame('MIGROS · 05.11.2026', $k->baslik);
        $this->assertArrayNotHasKey('ham_metin', $k->icerik);
    }

    public function test_tur_suzgeci_sayfalama_siralama_ve_tur_sayilari(): void
    {
        $this->evParasi(5000);
        foreach (['A', 'B', 'C'] as $i => $ad) {
            Carbon::setTestNow('2026-11-1'.($i + 3).' 10:00:00');
            $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['aciklama' => $ad]))->json('gider.id');
            $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();
        }
        $b = $this->postJson('/api/v1/aile/borclar', ['yon' => 'alacak', 'kisi' => 'Ayşe', 'tutar' => 10, 'tarih' => '2026-11-01'])->json('borc.id');
        $this->deleteJson("/api/v1/aile/borclar/{$b}")->assertOk();

        $tum = $this->getJson('/api/v1/aile/silinenler')->assertOk()->assertJsonPath('toplam', 4);
        $this->assertSame('borc', $tum->json('kayitlar.0.tur'), 'en yeni silinen en üstte');
        $this->assertEquals(['gider' => 3, 'borc' => 1], $tum->json('tur_sayilari'));
        $gider = $this->getJson('/api/v1/aile/silinenler?tur=gider')->assertOk()->assertJsonPath('toplam', 3);
        $this->assertSame(['C', 'B', 'A'], collect($gider->json('kayitlar'))->map(fn ($k) => explode(' ·', $k['baslik'])[0])->all());
        $this->getJson('/api/v1/aile/silinenler?tur=yok')->assertStatus(422);
        $this->getJson('/api/v1/aile/silinenler?sayfa=2')->assertOk()->assertJsonCount(0, 'kayitlar')->assertJsonPath('sonraki', false);
    }

    public function test_konsoldan_silme_sistem_yazilir_ve_arsiv_yazma_ucu_yoktur(): void
    {
        $hane = $this->ben->hane_id;
        $g = DuzenliGider::create(['hane_id' => $hane, 'aciklama' => 'Eski', 'tutar' => 5, 'tarih' => '2026-11-01', 'donem' => '2026-11']);
        auth()->logout();
        $g->delete();
        $this->assertSame('Sistem', SilinenKaydi::firstOrFail()->silen_ad);

        $this->actingAs($this->ben);
        $id = SilinenKaydi::first()->id;
        $this->deleteJson("/api/v1/aile/silinenler/{$id}")->assertStatus(405);
        $this->putJson("/api/v1/aile/silinenler/{$id}", [])->assertStatus(405);
        $this->postJson('/api/v1/aile/silinenler', [])->assertStatus(405);
    }

    public function test_baska_hane_gormez_ayrintiyi_acamaz(): void
    {
        $this->evParasi();
        $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider())->json('gider.id');
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();
        $kayit = SilinenKaydi::firstOrFail();

        $baska = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($baska);
        $this->actingAs($baska);
        $this->getJson('/api/v1/aile/silinenler')->assertOk()->assertJsonPath('toplam', 0)->assertJsonCount(0, 'kayitlar');
        $this->getJson('/api/v1/aile/silinenler/'.$kayit->id)->assertNotFound();
    }

    public function test_hanesiz_kullanici_yalniz_kendi_sildigini_gorur(): void
    {
        $tekil = User::factory()->create(['hane_id' => null]);
        $this->actingAs($tekil);
        $hesap = Hesap::factory()->create(['user_id' => $tekil->id]);
        $this->deleteJson("/api/v1/hesaplar/{$hesap->id}")->assertOk();
        $this->assertNull(SilinenKaydi::firstOrFail()->hane_id);
        $this->getJson('/api/v1/aile/silinenler')->assertOk()->assertJsonPath('toplam', 1);
        $this->actingAs($this->ben);
        $this->getJson('/api/v1/aile/silinenler')->assertOk()->assertJsonPath('toplam', 0);
    }
}
