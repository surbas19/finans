<?php

namespace Tests\Feature;

use App\Models\FiyatGecmisi;
use App\Models\Gelir;
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

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        $hane = app(HaneYonetimi::class)->hazirla($this->ben);
        $this->benUye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->esUye = HaneUyesi::create(['hane_id' => $hane->id, 'ad' => 'Eşim', 'aktif' => true])->id;
    }

    private function gelir(array $alanlar = []): array
    {
        return [
            'uye_id' => $this->benUye, 'tur' => 'maas', 'duzen' => 'duzenli', 'tutar' => 50000,
            'para_birimi' => 'TRY', 'tarih' => '2026-11-05', 'donem' => '2026-11', ...$alanlar,
        ];
    }

    public function test_gecmis_doneme_gelir_ve_kisi_hane_toplamlari(): void
    {
        $this->actingAs($this->ben);
        // Mart 2025 maaşı bugün girilir: Mart'a yazılır, bu aya kaymaz
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tarih' => '2025-03-31', 'donem' => '2025-03', 'tutar' => 42000.5]))
            ->assertCreated()->assertJsonPath('gelir.donem', '2025-03')->assertJsonPath('gelir.tl_tutar', 42000.5);
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tur' => 'ek_is', 'duzen' => 'duzensiz', 'tutar' => 7500.25, 'aciklama' => 'Çeviri']))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['uye_id' => $this->esUye, 'tutar' => 61000]))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['uye_id' => $this->esUye, 'tur' => 'prim', 'duzen' => 'tek_seferlik', 'tutar' => 0.1]))->assertCreated();
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['uye_id' => $this->esUye, 'tur' => 'prim', 'duzen' => 'tek_seferlik', 'tutar' => 0.2]))->assertCreated();

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
        $this->assertSame('prim', $kisiler[$this->esUye]['turler'][1]['tur']);
        // Varsayılan: bu ay; yıl süzgeci
        $this->getJson('/api/v1/aile/gelirler')->assertJsonCount(4, 'gelirler');
        $this->getJson('/api/v1/aile/gelirler?yil=2025')->assertJsonCount(1, 'gelirler')->assertJsonPath('ozet', null);
        $this->getJson("/api/v1/aile/gelirler?donem=2026-11&uye_id={$this->esUye}&tur=prim")->assertJsonCount(2, 'gelirler');
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
        $this->postJson('/api/v1/aile/gelir-kaynaklari', ['uye_id' => $this->benUye, 'tur' => 'maas', 'ad' => 'ABC', 'duzen' => 'duzenli'])
            ->assertJsonValidationErrors('siklik');
    }

    public function test_beklenen_gelir_toplama_katilmaz_ve_alininca_duser(): void
    {
        $this->actingAs($this->ben);
        $maas = $this->postJson('/api/v1/aile/gelir-kaynaklari', [
            'uye_id' => $this->benUye, 'tur' => 'maas', 'ad' => 'ABC A.Ş.', 'duzen' => 'duzenli', 'siklik' => 'aylik',
            'beklenen_tutar' => 50000, 'gun' => 31, 'baslangic' => '2026-01-01',
        ])->assertCreated()->json('kaynak');
        $kira = $this->postJson('/api/v1/aile/gelir-kaynaklari', [
            'uye_id' => $this->esUye, 'tur' => 'kira', 'ad' => 'Daire', 'duzen' => 'duzenli', 'siklik' => 'uc_aylik',
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
        $this->postJson('/api/v1/aile/gelirler', $this->gelir(['tur' => 'serbest', 'duzen' => 'duzensiz', 'tutar' => 1000, 'para_birimi' => 'USD', 'tarih' => '2026-10-31', 'donem' => '2026-10']))
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
        $kaynak = $this->postJson('/api/v1/aile/gelir-kaynaklari', ['uye_id' => $this->benUye, 'tur' => 'kira', 'ad' => 'Dükkan', 'duzen' => 'duzensiz'])->json('kaynak');

        $yabanci = User::factory()->create();
        $this->actingAs($yabanci)->getJson('/api/v1/aile/gelirler?donem=2026-11')->assertOk()
            ->assertJsonCount(0, 'gelirler')->assertJsonPath('ozet.toplam', 0);
        $this->actingAs($yabanci)->getJson('/api/v1/aile/gelir-kaynaklari')->assertJsonCount(0, 'kaynaklar');
        $this->actingAs($yabanci)->putJson("/api/v1/aile/gelirler/{$gelir['id']}", $this->gelir(['tutar' => 1]))->assertForbidden();
        $this->actingAs($yabanci)->deleteJson("/api/v1/aile/gelirler/{$gelir['id']}")->assertForbidden();
        $this->actingAs($yabanci)->deleteJson("/api/v1/aile/gelir-kaynaklari/{$kaynak['id']}")->assertForbidden();
        // Başka hanenin üyesine / kaynağına gelir yazılamaz
        $this->actingAs($yabanci)->postJson('/api/v1/aile/gelirler', $this->gelir())->assertJsonValidationErrors('uye_id');
        $this->assertSame(1, Gelir::count());

        // Hanedeki eş (ayrı giriş) aynı gelirleri görür ve düzeltir
        $es = User::factory()->create();
        app(HaneYonetimi::class)->tasi($es, $this->ben->hane);
        $this->actingAs($es)->getJson('/api/v1/aile/gelirler?donem=2026-11')->assertJsonCount(1, 'gelirler');
        $this->actingAs($es)->putJson("/api/v1/aile/gelirler/{$gelir['id']}", $this->gelir(['tutar' => 51000]))->assertOk()
            ->assertJsonPath('gelir.tl_tutar', 51000);
    }
}
