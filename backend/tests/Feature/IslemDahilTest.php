<?php

namespace Tests\Feature;

use App\Enums\IslemTuru;
use App\Models\Fiyat;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Aile\HaneYonetimi;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Tek tek işlem düzeyinde "genel toplama dahil" bayrağı; işlem numarası ve işlem (kayıt) zamanı.
 */
class IslemDahilTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private Hesap $hesap2;

    private Varlik $gram;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Evde']);
        $this->hesap2 = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Banka']);
        $this->gram = $this->varlik('GRAM', 'altin', 3000);
        $this->actingAs($this->ben);
    }

    private function islem(IslemTuru $tur, float $miktar, ?float $fiyat, string $tarih, ?Hesap $hesap = null): Islem
    {
        $hesap ??= $this->hesap;

        return Islem::create(['user_id' => $hesap->user_id, 'hesap_id' => $hesap->id, 'varlik_id' => $this->gram->id, 'tur' => $tur, 'miktar' => $miktar, 'birim_fiyat' => $fiyat, 'komisyon' => 0, 'tarih' => $tarih]);
    }

    private function toplam(): float
    {
        return (float) app(PortfoyHesabi::class)->hesapla($this->ben)['toplam']['deger'];
    }

    public function test_hariç_islem_toplama_ve_zekata_girmez_geri_acilinca_doner(): void
    {
        $a1 = $this->islem(IslemTuru::Alis, 10, 2000, '2026-10-01'); // 30.000
        $a2 = $this->islem(IslemTuru::Alis, 4, 2500, '2026-10-05');  // 12.000
        $this->assertEqualsWithDelta(42000, $this->toplam(), 0.01);

        $this->putJson("/api/v1/islemler/{$a2->id}/dahil", ['dahil' => false])->assertOk()->assertJsonPath('dahil', false);
        $this->assertEqualsWithDelta(30000, $this->toplam(), 0.01);
        $ozet = $this->getJson('/api/v1/ozet')->assertOk();
        $ozet->assertJsonPath('toplam.deger', 30000);
        // İşlem listesinde durur, dahil=false, numara ve kayıt zamanı taşır
        $liste = collect($this->getJson('/api/v1/islemler')->assertOk()->json('islemler'));
        $satir = $liste->firstWhere('id', $a2->id);
        $this->assertFalse($satir['dahil']);
        $this->assertSame('İ-'.$a2->id, $satir['no']);
        $this->assertStringStartsWith('2026-11-15T10:00:00', $satir['islem_zamani']);
        $this->assertSame('2026-10-05', $satir['tarih']); // alım tarihi ayrı
        $this->assertTrue($liste->firstWhere('id', $a1->id)['dahil']);

        // Zekât: hariç işlemin değeri brüte girmez
        Fiyat::updateOrCreate(['varlik_id' => $this->gram->id], ['fiyat' => 3000, 'kaynak' => 'elle', 'zaman' => now()]);
        $r = $this->postJson('/api/v1/aile/zekat/hesapla')->assertOk();
        $this->assertEqualsWithDelta(30000, (float) $r->json('brut'), 0.01);

        $this->putJson("/api/v1/islemler/{$a2->id}/dahil", ['dahil' => true])->assertOk();
        $this->assertEqualsWithDelta(42000, $this->toplam(), 0.01);
    }

    public function test_alisi_kapatmak_dahil_kalan_satisi_acikta_birakiyorsa_reddedilir(): void
    {
        $alis = $this->islem(IslemTuru::Alis, 5, 2000, '2026-10-01');
        $satis = $this->islem(IslemTuru::Satis, 3, 2500, '2026-10-10');

        $this->putJson("/api/v1/islemler/{$alis->id}/dahil", ['dahil' => false])->assertStatus(422)->assertJsonValidationErrors('dahil');
        $this->assertTrue($alis->fresh()->dahil);
        // Önce satışı çıkar, sonra alışı çıkar
        $this->putJson("/api/v1/islemler/{$satis->id}/dahil", ['dahil' => false])->assertOk();
        $this->putJson("/api/v1/islemler/{$alis->id}/dahil", ['dahil' => false])->assertOk();
        $this->assertEqualsWithDelta(0, $this->toplam(), 0.01);
        // Alışı geri açmak serbest; satış hâlâ hariç
        $this->putJson("/api/v1/islemler/{$alis->id}/dahil", ['dahil' => true])->assertOk();
        $this->assertEqualsWithDelta(15000, $this->toplam(), 0.01);
    }

    public function test_aktarim_cifti_birlikte_degisir(): void
    {
        $this->islem(IslemTuru::Alis, 4, 2000, '2026-10-01');
        $this->postJson("/api/v1/hesaplar/{$this->hesap->id}/pozisyonlar/{$this->gram->id}/aktar", ['hedef_hesap_id' => $this->hesap2->id, 'miktar' => 2, 'tarih' => '2026-11-10'])->assertCreated();
        $satis = Islem::where('tur', 'satis')->first();

        $this->putJson("/api/v1/islemler/{$satis->id}/dahil", ['dahil' => false])->assertOk();
        $this->assertSame(0, Islem::whereNotNull('aktarim_kodu')->where('dahil', true)->count()); // eşi de hariç
        $this->putJson("/api/v1/islemler/{$satis->id}/dahil", ['dahil' => true])->assertOk();
        $this->assertSame(0, Islem::whereNotNull('aktarim_kodu')->where('dahil', false)->count());
    }

    public function test_dogrulama_ve_baska_hane(): void
    {
        $i = $this->islem(IslemTuru::Alis, 1, 2000, '2026-10-01');
        $this->putJson("/api/v1/islemler/{$i->id}/dahil", [])->assertStatus(422)->assertJsonValidationErrors('dahil');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci)->putJson("/api/v1/islemler/{$i->id}/dahil", ['dahil' => false])->assertForbidden();
        $this->assertTrue($i->fresh()->dahil);
    }

    public function test_islem_zamani_duzenlemeyle_degismez(): void
    {
        $i = $this->islem(IslemTuru::Alis, 1, 2000, '2026-10-01');
        Carbon::setTestNow('2026-11-20 18:30:00');
        $this->putJson("/api/v1/islemler/{$i->id}", ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->gram->id, 'tur' => 'alis', 'miktar' => 2, 'birim_fiyat' => 2100, 'tarih' => '2026-10-02'])
            ->assertOk()->assertJsonPath('islem.tarih', '2026-10-02');
        $liste = collect($this->getJson('/api/v1/islemler')->json('islemler'))->firstWhere('id', $i->id);
        $this->assertStringStartsWith('2026-11-15T10:00:00', $liste['islem_zamani']); // kayıt zamanı değişmedi
        $this->assertSame('2026-10-02', $liste['tarih']);
    }
}
