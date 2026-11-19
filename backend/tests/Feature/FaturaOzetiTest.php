<?php

namespace Tests\Feature;

use App\Models\FaturaTuru;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faturalar ekranı: ay özeti (durum toplamları kuruşla, önceki ay, gelmeyen faturalar, dönemi bilinmeyen) ve
 * sağlayıcı geçmişi (ortalama, en yüksek, 12 ay serisi); başka hane göremez.
 */
class FaturaOzetiTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    /** @var array<string, int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);

        $tur = fn (string $ad): int => FaturaTuru::whereNull('hane_id')->where('ad', $ad)->value('id');
        $saglayici = fn (string $ad, string $turAd): int => $this->postJson('/api/v1/aile/saglayicilar', [
            'ad' => $ad, 'fatura_turu_id' => $tur($turAd), 'desenler' => [],
        ])->assertCreated()->json('saglayici.id');
        $this->s = [
            'enerjisa' => $saglayici('Enerjisa', 'Elektrik'),
            'iski' => $saglayici('İSKİ', 'Su'),
            'igdas' => $saglayici('İGDAŞ', 'Doğalgaz'),
            'telekom' => $saglayici('Türk Telekom', 'Elektrik'),
        ];
    }

    private function fatura(string $s, ?string $donem, float $tutar, string $durum = 'odendi', ?string $sonOdeme = null): int
    {
        return $this->postJson('/api/v1/aile/faturalar', [
            'saglayici_id' => $this->s[$s], 'hizmet_donemi' => $donem, 'tutar' => $tutar, 'durum' => $durum,
            'son_odeme_tarihi' => $sonOdeme, 'odeme_tarihi' => $durum === 'odendi' ? '2026-11-14' : null,
        ])->assertCreated()->json('fatura.id');
    }

    public function test_ay_ozeti_durumlar_onceki_ay_gelmeyenler_ve_bilinmeyenler(): void
    {
        $this->fatura('enerjisa', '2026-11', 1284.6);
        $this->fatura('iski', '2026-11', 412.3, 'bekliyor', '2026-11-12'); // gecikti
        $this->fatura('igdas', '2026-11', 1009.3, 'bekliyor', '2026-11-24');
        $this->fatura('enerjisa', '2026-10', 1146.9);
        $this->fatura('telekom', '2026-09', 549.9); // son 3 ayda var, bu ay yok: gelmedi
        $this->fatura('telekom', '2026-10', 549.9);
        $this->fatura('iski', null, 100);

        $this->getJson('/api/v1/aile/faturalar/ozet?donem=2026-11')->assertOk()
            ->assertJsonPath('toplam', 2706.2)
            ->assertJsonPath('adet', 3)->assertJsonPath('beklenen', 4)
            ->assertJsonPath('odendi.tutar', 1284.6)->assertJsonPath('odendi.adet', 1)
            ->assertJsonPath('bekliyor.tutar', 1009.3)->assertJsonPath('gecikti.tutar', 412.3)
            ->assertJsonPath('onceki_donem', '2026-10')->assertJsonPath('onceki_toplam', 1696.8)
            ->assertJsonCount(1, 'gelmeyenler')
            ->assertJsonPath('gelmeyenler.0.saglayici_ad', 'Türk Telekom')
            ->assertJsonPath('gelmeyenler.0.son_donem', '2026-10')->assertJsonPath('gelmeyenler.0.son_tutar', 549.9)
            ->assertJsonPath('bilinmeyen_adet', 1);

        // Pasif sağlayıcı ve 3 aydan eski fatura "gelmedi" sayılmaz
        $this->putJson("/api/v1/aile/saglayicilar/{$this->s['telekom']}", ['ad' => 'Türk Telekom', 'fatura_turu_id' => FaturaTuru::whereNull('hane_id')->where('ad', 'Elektrik')->value('id'), 'aktif' => false])->assertOk();
        $this->getJson('/api/v1/aile/faturalar/ozet?donem=2026-11')->assertJsonCount(0, 'gelmeyenler');
        $this->getJson('/api/v1/aile/faturalar/ozet?donem=2027-03')->assertJsonCount(0, 'gelmeyenler')->assertJsonPath('beklenen', 0);
    }

    public function test_saglayici_gecmisi(): void
    {
        $this->fatura('enerjisa', '2026-11', 1284.6);
        $this->fatura('enerjisa', '2026-10', 1146.9);
        $this->fatura('enerjisa', '2025-08', 1411.2); // 12 aydan eski: seride ve 12 ay toplamında yok
        $this->fatura('enerjisa', null, 100);

        $yanit = $this->getJson("/api/v1/aile/saglayicilar/{$this->s['enerjisa']}/gecmis")->assertOk()
            ->assertJsonPath('saglayici.ad', 'Enerjisa')
            ->assertJsonPath('adet', 4)
            ->assertJsonPath('ortalama', 985.68) // 3942,70 / 4
            ->assertJsonPath('en_yuksek.tutar', 1411.2)->assertJsonPath('en_yuksek.donem', '2025-08')
            ->assertJsonPath('son_12_ay', 2431.5)
            ->assertJsonCount(12, 'seri')
            ->assertJsonPath('seri.0.donem', '2025-12')->assertJsonPath('seri.0.tutar', null)
            ->assertJsonPath('seri.11.donem', '2026-11')->assertJsonPath('seri.11.tutar', 1284.6)
            ->assertJsonPath('faturalar.0.hizmet_donemi', '2026-11')->assertJsonPath('faturalar.3.hizmet_donemi', null);
        $this->assertSame('Enerjisa', $yanit->json('faturalar.0.saglayici_ad'));
    }

    public function test_baska_hane_goremez(): void
    {
        $this->fatura('enerjisa', '2026-11', 500);
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);

        $this->actingAs($yabanci)->getJson("/api/v1/aile/saglayicilar/{$this->s['enerjisa']}/gecmis")->assertForbidden();
        $this->getJson('/api/v1/aile/faturalar/ozet?donem=2026-11')->assertOk()
            ->assertJsonPath('toplam', 0)->assertJsonPath('adet', 0)->assertJsonCount(0, 'gelmeyenler');
    }
}
