<?php

namespace Tests\Feature;

use App\Models\HarcamaKategorisi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Düzenli giderler: kaynak (şablon), beklenen gider (toplama katılmaz), gerçekleşen gider (aylık gidere girer),
 * aynı dönem tekrarı, hane izolasyonu.
 */
class GiderTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kategori;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        $hane = app(HaneYonetimi::class)->hazirla($this->ben);
        $this->kategori = HarcamaKategorisi::where('hane_id', $hane->id)->value('id');
    }

    /**
     * @param  array<string, mixed>  $alan
     * @return array<string, mixed>
     */
    private function kaynak(array $alan = []): array
    {
        return ['ad' => 'Okul', 'siklik' => 'aylik', 'beklenen_tutar' => 15000, 'gun' => 5, 'baslangic' => '2026-09-01', 'kategori_id' => $this->kategori, ...$alan];
    }

    public function test_kaynak_beklenen_gider_uretir_odenince_gidere_girer_ve_beklenmez(): void
    {
        $this->actingAs($this->ben);
        $id = $this->postJson('/api/v1/aile/gider-kaynaklari', $this->kaynak())->assertCreated()->assertJsonPath('kaynak.ad', 'Okul')->json('kaynak.id');

        $ozet = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()
            ->assertJsonPath('gider.toplam', 0)->assertJsonPath('gider.duzenli.toplam', 0)
            ->assertJsonCount(1, 'gider.beklenenler')->assertJsonPath('gider.beklenenler.0.tarih', '2026-11-05');
        $this->assertSame($id, $ozet->json('gider.beklenenler.0.kaynak.id'));
        // Başlangıçtan önceki ay beklenmez
        $this->getJson('/api/v1/aile/ozet?ay=2026-08')->assertJsonCount(0, 'gider.beklenenler');

        $this->postJson('/api/v1/aile/duzenli-giderler', ['gider_kaynagi_id' => $id, 'aciklama' => 'Okul', 'tutar' => 15000.5, 'tarih' => '2026-11-05', 'donem' => '2026-11'])
            ->assertCreated()->assertJsonPath('gider.kategori_id', $this->kategori);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()
            ->assertJsonPath('gider.toplam', 15000.5)->assertJsonPath('gider.duzenli.toplam', 15000.5)
            ->assertJsonCount(0, 'gider.beklenenler')->assertJsonPath('gider.kategoriler.0.toplam', 15000.5)
            ->assertJsonPath('fark', -15000.5);

        // Aynı kaynağın aynı dönemi ikinci kez girilmez
        $this->postJson('/api/v1/aile/duzenli-giderler', ['gider_kaynagi_id' => $id, 'aciklama' => 'Okul', 'tutar' => 10, 'tarih' => '2026-11-06', 'donem' => '2026-11'])
            ->assertStatus(422)->assertJsonValidationErrors('donem');
    }

    public function test_kaynaksiz_tek_seferlik_gider_ve_gecmis_donem(): void
    {
        $this->actingAs($this->ben);
        $id = $this->postJson('/api/v1/aile/duzenli-giderler', ['aciklama' => 'Tamir', 'tutar' => 1200, 'tarih' => '2026-03-02', 'donem' => '2026-03'])
            ->assertCreated()->json('gider.id');
        $this->getJson('/api/v1/aile/ozet?ay=2026-03')->assertJsonPath('gider.toplam', 1200)->assertJsonPath('gider.kategoriler.0.ad', 'Kategorisiz');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 0);
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", ['aciklama' => 'Tamir', 'tutar' => 1500, 'tarih' => '2026-03-02', 'donem' => '2026-03'])->assertOk();
        $this->getJson('/api/v1/aile/duzenli-giderler?donem=2026-03')->assertJsonPath('giderler.0.tutar', 1500);
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();
        $this->getJson('/api/v1/aile/ozet?ay=2026-03')->assertJsonPath('gider.toplam', 0);
        // Gelecek tarihli gider ve uzak gelecek dönem reddedilir
        $this->postJson('/api/v1/aile/duzenli-giderler', ['aciklama' => 'x', 'tutar' => 1, 'tarih' => '2026-11-20', 'donem' => '2026-11'])->assertStatus(422);
        $this->postJson('/api/v1/aile/duzenli-giderler', ['aciklama' => 'x', 'tutar' => 1, 'tarih' => '2026-11-13', 'donem' => '2027-05'])->assertStatus(422);
    }

    public function test_sikliga_gore_beklenir_ve_bitis_sonrasi_beklenmez(): void
    {
        $this->actingAs($this->ben);
        $this->postJson('/api/v1/aile/gider-kaynaklari', $this->kaynak(['ad' => 'Aidat', 'siklik' => 'uc_aylik', 'baslangic' => '2026-08-01', 'bitis' => '2026-11-30']))->assertCreated();
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonCount(1, 'gider.beklenenler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonCount(0, 'gider.beklenenler');
        $this->getJson('/api/v1/aile/ozet?ay=2027-02')->assertJsonCount(0, 'gider.beklenenler');
    }

    public function test_baska_hane_gormez_ve_degistiremez(): void
    {
        $this->actingAs($this->ben);
        $kaynak = $this->postJson('/api/v1/aile/gider-kaynaklari', $this->kaynak())->json('kaynak.id');
        $gider = $this->postJson('/api/v1/aile/duzenli-giderler', ['aciklama' => 'Tamir', 'tutar' => 100, 'tarih' => '2026-11-02', 'donem' => '2026-11'])->json('gider.id');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/gider-kaynaklari')->assertJsonCount(0, 'kaynaklar');
        $this->getJson('/api/v1/aile/duzenli-giderler?donem=2026-11')->assertJsonCount(0, 'giderler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 0)->assertJsonCount(0, 'gider.beklenenler');
        $this->putJson("/api/v1/aile/gider-kaynaklari/{$kaynak}", $this->kaynak())->assertForbidden();
        $this->deleteJson("/api/v1/aile/gider-kaynaklari/{$kaynak}")->assertForbidden();
        $this->putJson("/api/v1/aile/duzenli-giderler/{$gider}", ['aciklama' => 'x', 'tutar' => 1, 'tarih' => '2026-11-02', 'donem' => '2026-11'])->assertForbidden();
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$gider}")->assertForbidden();
        // Başkasının kaynağına / kategorisine bağlanamaz
        $this->postJson('/api/v1/aile/duzenli-giderler', ['gider_kaynagi_id' => $kaynak, 'aciklama' => 'x', 'tutar' => 1, 'tarih' => '2026-11-02', 'donem' => '2026-11'])->assertStatus(422);
        $this->postJson('/api/v1/aile/gider-kaynaklari', $this->kaynak())->assertStatus(422)->assertJsonValidationErrors('kategori_id');
    }
}
