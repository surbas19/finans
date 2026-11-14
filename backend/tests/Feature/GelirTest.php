<?php

namespace Tests\Feature;

use App\Models\FiyatGecmisi;
use App\Models\Gelir;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Gelirler: geçmiş döneme kayıt, beklenen gelir (toplama katılmaz), döviz, hane izolasyonu.
 */
class GelirTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private int $benUye;

    private int $esUye;

    /** @var array<string, int> hanenin varsayılan gelir türleri: ad => id */
    private array $tur;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        $hane = app(HaneYonetimi::class)->hazirla($this->ben);
        $this->benUye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->esUye = HaneUyesi::create(['hane_id' => $hane->id, 'ad' => 'Eşim', 'aktif' => true])->id;
        $this->tur = GelirTuru::where('hane_id', $hane->id)->pluck('id', 'ad')->all();
    }

    private function gelir(array $alanlar = []): array
    {
        return [
            'uye_id' => $this->benUye, 'gelir_turu_id' => $this->tur['Maaş'], 'duzen' => 'duzenli', 'tutar' => 50000,
            'para_birimi' => 'TRY', 'tarih' => '2026-11-05', 'donem' => '2026-11', ...$alanlar,
        ];
    }

    public function test_gecmis_doneme_gelir_ve_kisi_hane_toplamlari(): void
    {
        $this->actingAs($this->ben);
        // Mart 2025 maaşı bugün girilir: Mart'a yazılır, bu aya kaymaz
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tarih' => '2025-03-31', 'donem' => '2025-03', 'tutar' => 42000.5]))
            ->assertCreated()->assertJsonPath('gelir.donem', '2025-03')->assertJsonPath('gelir.tl_tutar', 42000.5);
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['gelir_turu_id' => $this->tur['Ek iş'], 'duzen' => 'duzensiz', 'tutar' => 7500.25, 'aciklama' => 'Çeviri']))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['uye_id' => $this->esUye, 'tutar' => 61000]))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['uye_id' => $this->esUye, 'gelir_turu_id' => $this->tur['Prim / ikramiye'], 'duzen' => 'tek_seferlik', 'tutar' => 0.1]))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['uye_id' => $this->esUye, 'gelir_turu_id' => $this->tur['Prim / ikramiye'], 'duzen' => 'tek_seferlik', 'tutar' => 0.2]))->assertCreated();

        $mart = $this->getJson('/api/v1/aile/gelirler?donem=2025-03')->assertOk();
        $mart->assertJsonCount(1, 'gelirler')->assertJsonPath('ozet.toplam', 42000.5);

        $kasim = $this->getJson('/api/v1/aile/gelirler?donem=2026-11')->assertOk();
        $kasim->assertJsonCount(4, 'gelirler')
            ->assertJsonPath('ozet.toplam', 68500.55)
            ->assertJsonPath('ozet.duzenli', 61000)
            ->assertJsonPath('ozet.duzensiz', 7500.25)
            ->assertJsonPath('ozet.tek_seferlik', 0.3);
        $kisiler = collect($kasim->json('ozet.kisiler'))->keyBy('uye.id');
        $this->assertSame(7500.25, $kisiler[$this->benUye]['toplam']);
        $this->assertEquals(61000.3, $kisiler[$this->esUye]['toplam']);
        $this->assertSame([$this->tur['Prim / ikramiye'], 'Prim / ikramiye'], [$kisiler[$this->esUye]['turler'][1]['gelir_turu_id'], $kisiler[$this->esUye]['turler'][1]['etiket']]);
        // Varsayılan: bu ay; yıl süzgeci
        $this->getJson('/api/v1/aile/gelirler')->assertJsonCount(4, 'gelirler');
        $this->getJson('/api/v1/aile/gelirler?yil=2025')->assertJsonCount(1, 'gelirler')->assertJsonPath('ozet', null);
        $this->getJson("/api/v1/aile/gelirler?donem=2026-11&uye_id={$this->esUye}&gelir_turu_id={$this->tur['Prim / ikramiye']}")->assertJsonCount(2, 'gelirler');
    }

    public function test_gelir_turu_eklenir_adi_degisir_kullanilan_silinmez(): void
    {
        $this->actingAs($this->ben);
        // Yeni hanede varsayılan 8 tür, sırasıyla
        $this->getJson('/api/v1/aile/gelir-turleri')->assertOk()->assertJsonCount(8, 'turler')
            ->assertJsonPath('turler.0.ad', 'Maaş')->assertJsonPath('turler.7.ad', 'Diğer');

        $ekDers = $this->postJson('/api/v1/aile/gelir-turleri', ['ad' => ' Ek ders '])->assertCreated()
            ->assertJsonPath('tur.ad', 'Ek ders')->assertJsonPath('tur.sira', 9)->json('tur.id');
        $this->postJson('/api/v1/aile/gelir-turleri', ['ad' => 'EK DERS'])->assertJsonValidationErrors('ad');
        $this->postJson('/api/v1/aile/gelir-turleri', ['ad' => 'x'])->assertJsonValidationErrors('ad');

        // Gelirde ve kaynakta kullanılır; raporda adıyla görünür, ad değişince rapor da değişir
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['gelir_turu_id' => $ekDers, 'duzen' => 'duzensiz', 'tutar' => 3200]))->assertCreated()
            ->assertJsonPath('gelir.gelir_turu_id', $ekDers);
        $this->postJson('/api/v1/aile/gelir-kaynaklari', ['uye_id' => $this->benUye, 'gelir_turu_id' => $ekDers, 'ad' => 'Lise', 'duzen' => 'duzensiz'])->assertCreated();
        $this->putJson("/api/v1/aile/gelir-turleri/{$ekDers}", ['ad' => 'Ek ders (lise)'])->assertOk();
        $ben = collect($this->getJson('/api/v1/aile/gelirler?donem=2026-11')->json('ozet.kisiler'))->firstWhere('uye.id', $this->benUye);
        $this->assertSame([[$ekDers, 'Ek ders (lise)']], array_map(fn (array $t): array => [$t['gelir_turu_id'], $t['etiket']], $ben['turler']));

        // Kullanılan tür silinmez; kullanılmayan silinir
        $this->deleteJson("/api/v1/aile/gelir-turleri/{$ekDers}")->assertJsonValidationErrors('tur')
            ->assertJsonPath('errors.tur.0', '"Ek ders (lise)" türünde 1 gelir ve 1 gelir kaynağı var; silmeden önce onları başka türe alın.');
        $burs = $this->postJson('/api/v1/aile/gelir-turleri', ['ad' => 'Burs'])->json('tur.id');
        $this->deleteJson("/api/v1/aile/gelir-turleri/{$burs}")->assertOk();
        $this->assertSame(9, GelirTuru::where('hane_id', $this->ben->hane_id)->count());

        // Başka hane: kendi varsayılanlarını görür, bu haneninkine dokunamaz, kullanamaz; aynı adı kendisi ekleyebilir
        $yabanci = User::factory()->create();
        $this->actingAs($yabanci)->getJson('/api/v1/aile/gelir-turleri')->assertJsonCount(8, 'turler');
        $this->actingAs($yabanci)->putJson("/api/v1/aile/gelir-turleri/{$ekDers}", ['ad' => 'X'])->assertForbidden();
        $this->actingAs($yabanci)->deleteJson("/api/v1/aile/gelir-turleri/{$this->tur['Diğer']}")->assertForbidden();
        $this->actingAs($yabanci)->postJson('/api/v1/aile/gelir-turleri', ['ad' => 'Ek ders (lise)'])->assertCreated();
        $yabanciUye = HaneUyesi::where('user_id', $yabanci->id)->value('id');
        $this->actingAs($yabanci)->postJson('/api/v1/aile/gelir-kaynaklari', ['uye_id' => $yabanciUye, 'gelir_turu_id' => $ekDers, 'ad' => 'X', 'duzen' => 'duzensiz'])
            ->assertJsonValidationErrors('gelir_turu_id');
        $this->assertSame('Ek ders (lise)', GelirTuru::find($ekDers)->ad);
    }

    public function test_dogrulama_kurallari(): void
    {
        $this->actingAs($this->ben);
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tutar' => 10.555]))->assertJsonValidationErrors('tutar');
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tarih' => '2026-11-14']))->assertJsonValidationErrors('tarih');
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['donem' => '2027-01']))->assertJsonValidationErrors('donem');
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['donem' => '2026-12']))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['donem' => '2026/11']))->assertJsonValidationErrors('donem');
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['para_birimi' => 'XYZ']))->assertJsonValidationErrors('para_birimi');
        $this->postJson('/api/v1/aile/gelir-kaynaklari', ['uye_id' => $this->benUye, 'gelir_turu_id' => $this->tur['Maaş'], 'ad' => 'ABC', 'duzen' => 'duzenli'])
            ->assertJsonValidationErrors('siklik');
    }

    public function test_beklenen_gelir_toplama_katilmaz_ve_alininca_duser(): void
    {
        $this->actingAs($this->ben);
        $maas = $this->postJson('/api/v1/aile/gelir-kaynaklari', [
            'uye_id' => $this->benUye, 'gelir_turu_id' => $this->tur['Maaş'], 'ad' => 'ABC A.Ş.', 'duzen' => 'duzenli', 'siklik' => 'aylik',
            'beklenen_tutar' => 50000, 'gun' => 31, 'baslangic' => '2026-01-01',
        ])->assertCreated()->json('kaynak');
        $kira = $this->postJson('/api/v1/aile/gelir-kaynaklari', [
            'uye_id' => $this->esUye, 'gelir_turu_id' => $this->tur['Kira geliri'], 'ad' => 'Daire', 'duzen' => 'duzenli', 'siklik' => 'uc_aylik',
            'beklenen_tutar' => 30000, 'baslangic' => '2026-02-10', 'bitis' => '2026-11-30',
        ])->assertCreated()->json('kaynak');

        // Kasım: maaş + (Şubat'tan 3 ayda bir: Şub, May, Ağu, Kas) kira beklenir; ikisi de toplama girmez
        $kasim = $this->getJson('/api/v1/aile/gelirler?donem=2026-11')->assertOk();
        $kasim->assertJsonPath('ozet.toplam', 0)->assertJsonCount(2, 'ozet.beklenenler');
        $this->assertSame('2026-11-30', $kasim->json('ozet.beklenenler.0.tarih')); // 31 → ay sonu
        // Ekim'de kira beklenmez; Şubat 2027'de bitişten sonra beklenmez; Aralık 2025'te başlangıçtan önce
        $this->getJson('/api/v1/aile/gelirler?donem=2026-10')->assertJsonCount(1, 'ozet.beklenenler');
        $this->getJson('/api/v1/aile/gelirler?donem=2025-12')->assertJsonCount(0, 'ozet.beklenenler');

        // Alındı: aynı kaynak + dönem bir kez
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['gelir_kaynagi_id' => $maas['id'], 'tutar' => 50000]))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['gelir_kaynagi_id' => $maas['id'], 'tutar' => 50000]))->assertJsonValidationErrors('donem');
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['gelir_kaynagi_id' => $kira['id']]))->assertJsonValidationErrors('gelir_kaynagi_id');
        $this->getJson('/api/v1/aile/gelirler?donem=2026-11')
            ->assertJsonPath('ozet.toplam', 50000)->assertJsonCount(1, 'ozet.beklenenler')
            ->assertJsonPath('ozet.beklenenler.0.kaynak.ad', 'Daire');

        // Kaynak silinince gelir kalır
        $this->deleteJson("/api/v1/aile/gelir-kaynaklari/{$maas['id']}")->assertOk();
        $this->assertSame(1, Gelir::count());
        $this->assertNull(Gelir::first()->gelir_kaynagi_id);
        // Kaydı olan üye silinmez, pasif olur
        $this->deleteJson("/api/v1/aile/uyeler/{$this->esUye}")->assertOk();
        $this->assertFalse((bool) HaneUyesi::find($this->esUye)->aktif);
    }

    public function test_doviz_geliri_o_gunun_kuruyla_yoksa_tl_karsiligi_istenir(): void
    {
        $this->actingAs($this->ben);
        $usd = $this->varlik('USD', 'doviz', 33);
        FiyatGecmisi::create(['varlik_id' => $usd->id, 'tarih' => '2026-10-30', 'kapanis' => 32.5]);

        // Cumartesi (31 Ekim): önceki kapanış (30 Ekim) kullanılır
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['gelir_turu_id' => $this->tur['Serbest çalışma'], 'duzen' => 'duzensiz', 'tutar' => 1000, 'para_birimi' => 'USD', 'tarih' => '2026-10-31', 'donem' => '2026-10']))
            ->assertCreated()->assertJsonPath('gelir.tl_tutar', 32500)->assertJsonPath('gelir.kur', 32.5);
        // Kur yok: TL karşılığı istenir; girilirse o kullanılır
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tutar' => 1000, 'para_birimi' => 'USD', 'tarih' => '2026-01-15', 'donem' => '2026-01']))
            ->assertJsonValidationErrors('tl_tutar');
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tutar' => 1000, 'para_birimi' => 'USD', 'tl_tutar' => 30100.75, 'tarih' => '2026-01-15', 'donem' => '2026-01']))
            ->assertCreated()->assertJsonPath('gelir.tl_tutar', 30100.75)->assertJsonPath('gelir.kur', null);
    }

    public function test_baska_hane_gelirleri_goremez_degistiremez(): void
    {
        $this->actingAs($this->ben);
        $gelir = $this->postJson('/api/v1/aile/gelirler', $this->gelir())->assertCreated()->json('gelir');
        $kaynak = $this->postJson('/api/v1/aile/gelir-kaynaklari', ['uye_id' => $this->benUye, 'gelir_turu_id' => $this->tur['Kira geliri'], 'ad' => 'Dükkan', 'duzen' => 'duzensiz'])->json('kaynak');

        $yabanci = User::factory()->create();
        $this->actingAs($yabanci)->getJson('/api/v1/aile/gelirler?donem=2026-11')->assertOk()
            ->assertJsonCount(0, 'gelirler')->assertJsonPath('ozet.toplam', 0);
        $this->actingAs($yabanci)->getJson('/api/v1/aile/gelir-kaynaklari')->assertJsonCount(0, 'kaynaklar');
        $this->actingAs($yabanci)->putJson("/api/v1/aile/gelirler/{$gelir['id']}", $this->gelir(['tutar' => 1]))->assertForbidden();
        $this->actingAs($yabanci)->deleteJson("/api/v1/aile/gelirler/{$gelir['id']}")->assertForbidden();
        $this->actingAs($yabanci)->deleteJson("/api/v1/aile/gelir-kaynaklari/{$kaynak['id']}")->assertForbidden();
        // Başka hanenin üyesine / türüne gelir yazılamaz
        $this->actingAs($yabanci)->postJson('/api/v1/aile/gelirler', $this->gelir())->assertJsonValidationErrors(['uye_id', 'gelir_turu_id']);
        $this->assertSame(1, Gelir::count());

        // Hanedeki eş (ayrı giriş) aynı gelirleri görür ve düzeltir
        $es = User::factory()->create();
        app(HaneYonetimi::class)->tasi($es, $this->ben->hane);
        $this->actingAs($es)->getJson('/api/v1/aile/gelirler?donem=2026-11')->assertJsonCount(1, 'gelirler');
        $this->actingAs($es)->putJson("/api/v1/aile/gelirler/{$gelir['id']}", $this->gelir(['tutar' => 51000]))->assertOk()
            ->assertJsonPath('gelir.tl_tutar', 51000);
    }
}
