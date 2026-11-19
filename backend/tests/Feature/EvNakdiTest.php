<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ev nakdi kasası: bakiye = eklenen − çıkarılan − ödeme yeri "ev" olan giderler; yetmeyen harcama / çıkarma reddedilir
 * (bakiye eksiye düşmez); banka ve belirtilmemiş düşmez; hane izolasyonu.
 */
class EvNakdiTest extends TestCase
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
    private function gider(array $alan = []): array
    {
        return ['aciklama' => 'Simit', 'tutar' => 40, 'tarih' => '2026-11-12', 'donem' => '2026-11', ...$alan];
    }

    private function ekle(float $tutar): void
    {
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => $tutar, 'tarih' => '2026-11-10'])->assertCreated();
    }

    public function test_bakiye_ekleme_cikarma_ve_ev_harcamasindan_olusur(): void
    {
        $this->actingAs($this->ben);
        $this->getJson('/api/v1/aile/ev-nakdi')->assertOk()->assertJsonPath('bakiye', 0)->assertJsonCount(0, 'hareketler');

        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 1000, 'tarih' => '2026-11-10'])->assertCreated()->assertJsonPath('bakiye', 1000);
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'cikarma', 'tutar' => 100.5, 'tarih' => '2026-11-11', 'not' => 'Bankaya yatırıldı'])->assertCreated()->assertJsonPath('bakiye', 899.5);

        // Ev harcaması düşer; belirtilmemiş (eski kayıt) düşmez
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'ev', 'tutar' => 49.5]))->assertCreated()->assertJsonPath('gider.odeme_yeri', 'ev');
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['tutar' => 70]))->assertCreated()->assertJsonPath('gider.odeme_yeri', null);

        $ozet = $this->getJson('/api/v1/aile/ev-nakdi')->assertOk()
            ->assertJsonPath('bakiye', 850)->assertJsonPath('giren', 1000)->assertJsonPath('cikan', 100.5)->assertJsonPath('harcama', 49.5);
        $this->assertCount(3, $ozet->json('hareketler'));
        $this->assertSame('harcama', $ozet->json('hareketler.0.tip'));

        // Bütçe gider toplamı ödeme yerinden etkilenmez (hepsi gider)
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()->assertJsonPath('gider.duzenli.toplam', 119.5);

        // Ev harcaması silinince bakiye geri gelir
        $evId = $ozet->json('hareketler.0.id');
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$evId}")->assertOk();
        $this->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('bakiye', 899.5);
    }

    public function test_yetmeyen_ev_harcamasi_ve_cikarma_reddedilir_bakiye_eksiye_dusmez(): void
    {
        $this->actingAs($this->ben);
        // Kasa boşken ev harcaması reddedilir
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'ev', 'tutar' => 25]))
            ->assertStatus(422)->assertJsonValidationErrors('tutar');
        $this->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('bakiye', 0);

        $this->ekle(100);
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'ev', 'tutar' => 100.01]))->assertStatus(422);
        $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'ev', 'tutar' => 100]))->assertCreated()->json('gider.id');
        $this->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('bakiye', 0);

        // Çıkarma bakiyeden fazla olamaz; harcanmış para silinemez
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'cikarma', 'tutar' => 1, 'tarih' => '2026-11-11'])->assertStatus(422)->assertJsonValidationErrors('tutar');
        $eklemeId = $this->getJson('/api/v1/aile/ev-nakdi')->json('hareketler.1.id');
        $this->deleteJson("/api/v1/aile/ev-nakdi/hareketler/{$eklemeId}")->assertStatus(422);

        // Güncellemede eski tutar bakiyeye geri sayılır: 100 → 60 olur, 120 olmaz
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['odeme_yeri' => 'ev', 'tutar' => 60]))->assertOk();
        $this->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('bakiye', 40);
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['odeme_yeri' => 'ev', 'tutar' => 120]))->assertStatus(422);
        // Banka'ya çevrilince ev kasası geri dolar (banka için hesap gerekir)
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['odeme_yeri' => 'banka', 'tutar' => 60]))->assertStatus(422)->assertJsonValidationErrors('hesap_id');
    }

    public function test_dogrulama(): void
    {
        $this->actingAs($this->ben);
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'x', 'tutar' => 10, 'tarih' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('tur');
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 0, 'tarih' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('tutar');
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 5, 'tarih' => '2026-12-31'])->assertStatus(422)->assertJsonValidationErrors('tarih');
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'sepet']))->assertStatus(422)->assertJsonValidationErrors('odeme_yeri');
    }

    public function test_baska_hane_gormez_ve_silemez(): void
    {
        $this->actingAs($this->ben);
        $id = $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 500, 'tarih' => '2026-11-10'])->json('hareketler.0.id');
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'ev']))->assertCreated();

        $baska = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($baska);
        $this->actingAs($baska);
        $this->getJson('/api/v1/aile/ev-nakdi')->assertOk()->assertJsonPath('bakiye', 0)->assertJsonCount(0, 'hareketler');
        $this->deleteJson("/api/v1/aile/ev-nakdi/hareketler/{$id}")->assertForbidden();
        // Başkasının bakiyesi kendi harcamasını karşılamaz
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['odeme_yeri' => 'ev']))->assertStatus(422);
    }
}
