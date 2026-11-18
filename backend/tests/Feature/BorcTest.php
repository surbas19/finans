<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Borç / alacak defteri: kayıt, kısmi ödeme, kalan, kapanış, fazla ödeme reddi, Bütçe'ye karışmaz, hane izolasyonu.
 */
class BorcTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
    }

    /**
     * @param  array<string, mixed>  $alan
     * @return array<string, mixed>
     */
    private function borc(array $alan = []): array
    {
        return ['yon' => 'borc', 'kisi' => 'Ahmet', 'tutar' => 1000, 'tarih' => '2026-11-01', ...$alan];
    }

    public function test_kismi_odeme_kalani_dusurur_ve_kapatir(): void
    {
        $this->actingAs($this->ben);
        $id = $this->postJson('/api/v1/aile/borclar', $this->borc(['vade' => '2026-12-01']))->assertCreated()
            ->assertJsonPath('borc.kalan', 1000)->assertJsonPath('borc.durum', 'acik')->json('borc.id');
        $this->postJson('/api/v1/aile/borclar', $this->borc(['yon' => 'alacak', 'kisi' => 'Ayşe', 'tutar' => 250.5]))->assertCreated();

        $this->getJson('/api/v1/aile/borclar')->assertOk()->assertJsonPath('toplam_borc', 1000)->assertJsonPath('toplam_alacak', 250.5);

        $this->postJson("/api/v1/aile/borclar/{$id}/odemeleri", ['tutar' => 400, 'tarih' => '2026-11-10'])->assertCreated()
            ->assertJsonPath('borc.odenen', 400)->assertJsonPath('borc.kalan', 600);
        // Kalandan fazla ödenemez
        $this->postJson("/api/v1/aile/borclar/{$id}/odemeleri", ['tutar' => 600.01, 'tarih' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('tutar');
        $odeme = $this->postJson("/api/v1/aile/borclar/{$id}/odemeleri", ['tutar' => 600, 'tarih' => '2026-11-12'])->assertCreated()
            ->assertJsonPath('borc.kalan', 0)->assertJsonPath('borc.durum', 'kapali')->json('borc.odemeler.0.id');

        $this->getJson('/api/v1/aile/borclar')->assertJsonPath('toplam_borc', 0);
        // Açık alacak önce, kapalı borç sonda
        $this->assertSame('alacak', $this->getJson('/api/v1/aile/borclar')->json('borclar.0.yon'));

        // Ödeme silinince yeniden açılır
        $this->deleteJson("/api/v1/aile/borclar/{$id}/odemeleri/{$odeme}")->assertOk()->assertJsonPath('borc.kalan', 600)->assertJsonPath('borc.durum', 'acik');
    }

    public function test_vade_gecmis_duzenleme_ve_butceye_karismaz(): void
    {
        $this->actingAs($this->ben);
        $id = $this->postJson('/api/v1/aile/borclar', $this->borc(['vade' => '2026-11-05']))->assertCreated()->assertJsonPath('borc.vade_gecti', true)->json('borc.id');
        $this->postJson("/api/v1/aile/borclar/{$id}/odemeleri", ['tutar' => 300, 'tarih' => '2026-11-10'])->assertCreated();

        // Tutar ödenenin altına indirilemez
        $this->putJson("/api/v1/aile/borclar/{$id}", $this->borc(['tutar' => 200]))->assertStatus(422)->assertJsonValidationErrors('tutar');
        $this->putJson("/api/v1/aile/borclar/{$id}", $this->borc(['tutar' => 1500, 'kisi' => 'Mehmet']))->assertOk()->assertJsonPath('borc.kalan', 1200)->assertJsonPath('borc.kisi', 'Mehmet');

        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()->assertJsonPath('gider.toplam', 0)->assertJsonPath('gelir.toplam', 0);
        $this->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('bakiye', 0);

        $this->deleteJson("/api/v1/aile/borclar/{$id}")->assertOk();
        $this->getJson('/api/v1/aile/borclar')->assertJsonCount(0, 'borclar');
    }

    public function test_dogrulama(): void
    {
        $this->actingAs($this->ben);
        $this->postJson('/api/v1/aile/borclar', $this->borc(['yon' => 'x']))->assertStatus(422)->assertJsonValidationErrors('yon');
        $this->postJson('/api/v1/aile/borclar', $this->borc(['kisi' => '']))->assertStatus(422)->assertJsonValidationErrors('kisi');
        $this->postJson('/api/v1/aile/borclar', $this->borc(['tutar' => -5]))->assertStatus(422)->assertJsonValidationErrors('tutar');
        $this->postJson('/api/v1/aile/borclar', $this->borc(['vade' => '2026-10-01']))->assertStatus(422)->assertJsonValidationErrors('vade');
    }

    public function test_baska_hane_gormez_degistiremez_odeme_ekleyemez(): void
    {
        $this->actingAs($this->ben);
        $id = $this->postJson('/api/v1/aile/borclar', $this->borc())->json('borc.id');
        $odeme = $this->postJson("/api/v1/aile/borclar/{$id}/odemeleri", ['tutar' => 10, 'tarih' => '2026-11-10'])->json('borc.odemeler.0.id');

        $baska = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($baska);
        $this->actingAs($baska);
        $this->getJson('/api/v1/aile/borclar')->assertOk()->assertJsonCount(0, 'borclar')->assertJsonPath('toplam_borc', 0);
        $this->putJson("/api/v1/aile/borclar/{$id}", $this->borc())->assertForbidden();
        $this->deleteJson("/api/v1/aile/borclar/{$id}")->assertForbidden();
        $this->postJson("/api/v1/aile/borclar/{$id}/odemeleri", ['tutar' => 10, 'tarih' => '2026-11-10'])->assertForbidden();
        $this->deleteJson("/api/v1/aile/borclar/{$id}/odemeleri/{$odeme}")->assertForbidden();
    }
}
