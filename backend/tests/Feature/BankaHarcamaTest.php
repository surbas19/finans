<?php

namespace Tests\Feature;

use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Banka ödeme yerli nakit harcama: seçilen portföy hesabının Nakit (TL) bakiyesinden `bag_turu = harcama` satışı olarak
 * düşer; düzenleme / silme işlemi günceller / siler; yetmeyen nakitte reddedilir; hane izolasyonu.
 */
class BankaHarcamaTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private int $nakitId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Maaş hesabı']);
        $this->nakitId = $this->varlik('TL', 'nakit', null, kaynak: 'sabit')->id;
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->nakitId, 'tur' => 'alis', 'miktar' => 1000, 'birim_fiyat' => 1, 'komisyon' => 0, 'tarih' => '2026-11-01']);
    }

    /**
     * @param  array<string, mixed>  $alan
     * @return array<string, mixed>
     */
    private function gider(array $alan = []): array
    {
        return ['aciklama' => 'Berber', 'tutar' => 300, 'tarih' => '2026-11-12', 'donem' => '2026-11', 'odeme_yeri' => 'banka', 'hesap_id' => $this->hesap->id, ...$alan];
    }

    private function nakit(): float
    {
        return (float) $this->getJson('/api/v1/aile/odeme-kaynaklari')->assertOk()->json('hesaplar.0.nakit');
    }

    public function test_banka_harcamasi_hesabin_nakdinden_satis_olarak_duser_duzenleme_ve_silme_gunceller(): void
    {
        $this->actingAs($this->ben);
        $this->assertSame(1000.0, $this->nakit());

        $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider())->assertCreated()
            ->assertJsonPath('gider.odeme_yeri', 'banka')->assertJsonPath('gider.hesap_id', $this->hesap->id)->json('gider.id');
        $this->assertSame(700.0, $this->nakit());
        $this->assertDatabaseHas('islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->nakitId, 'tur' => 'satis', 'miktar' => 300, 'bag_turu' => 'harcama']);
        $this->getJson("/api/v1/hesaplar/{$this->hesap->id}")->assertJsonPath('hesap.deger', 700);

        // Tutar güncellenince satış yeniden yazılır
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['tutar' => 450]))->assertOk();
        $this->assertSame(550.0, $this->nakit());
        $this->assertSame(1, Islem::where('bag_turu', 'harcama')->count());

        // Ev'e çevrilince hesap geri dolar (kasa boş olduğu için önce ekleme gerekir)
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['odeme_yeri' => 'ev', 'hesap_id' => null, 'tutar' => 450]))->assertStatus(422);
        $this->assertSame(550.0, $this->nakit());
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 500, 'tarih' => '2026-11-10'])->assertCreated();
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['odeme_yeri' => 'ev', 'hesap_id' => null, 'tutar' => 450]))->assertOk()->assertJsonPath('gider.hesap_id', null);
        $this->assertSame(1000.0, $this->nakit());
        $this->assertSame(0, Islem::where('bag_turu', 'harcama')->count());

        // Banka'ya dönünce yeniden düşer; silinince para hesaba geri döner
        $this->putJson("/api/v1/aile/duzenli-giderler/{$id}", $this->gider(['tutar' => 200]))->assertOk();
        $this->assertSame(800.0, $this->nakit());
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();
        $this->assertSame(1000.0, $this->nakit());
        $this->assertSame(0, Islem::where('bag_turu', 'harcama')->count());
    }

    public function test_yetmeyen_nakitte_reddedilir_ve_hesap_zorunludur(): void
    {
        $this->actingAs($this->ben);
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['tutar' => 1000.01]))->assertStatus(422)->assertJsonValidationErrors('tutar');
        $this->assertSame(1000.0, $this->nakit());
        $this->assertSame(0, Islem::where('bag_turu', 'harcama')->count());
        $this->assertDatabaseCount('duzenli_giderler', 0);
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['hesap_id' => null]))->assertStatus(422)->assertJsonValidationErrors('hesap_id');
        // Nakit olmayan tarihte (hesaba nakit girişinden önce) de reddedilir
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['tarih' => '2026-10-20', 'donem' => '2026-10']))->assertStatus(422);
        // Tam bakiye geçer
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider(['tutar' => 1000]))->assertCreated();
        $this->assertSame(0.0, $this->nakit());
    }

    public function test_harcama_satisi_portfoy_ekranindan_duzenlenmez_silinmez(): void
    {
        $this->actingAs($this->ben);
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider())->assertCreated();
        $satis = Islem::where('bag_turu', 'harcama')->firstOrFail();
        $this->deleteJson("/api/v1/islemler/{$satis->id}")->assertStatus(422)->assertJsonValidationErrors('islem');
        $this->putJson("/api/v1/islemler/{$satis->id}", ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->nakitId, 'tur' => 'satis', 'miktar' => 1, 'tarih' => '2026-11-12'])->assertStatus(422)->assertJsonValidationErrors('islem');
    }

    public function test_baska_hanenin_hesabi_reddedilir_ve_kaynaklarda_gorunmez(): void
    {
        $baska = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($baska);
        $this->actingAs($baska);
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->gider())->assertStatus(422)->assertJsonValidationErrors('hesap_id');
        $this->getJson('/api/v1/aile/odeme-kaynaklari')->assertOk()->assertJsonCount(0, 'hesaplar')->assertJsonPath('ev.bakiye', 0);
        $this->assertSame(0, Islem::where('bag_turu', 'harcama')->count());
    }
}
