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
 * Yatırım satırı (hesap + varlık) "genel toplama dahil" bayrağı: dahil olmayan satır listede durur ama genel
 * toplam, hesap toplamı, dağılım, varlık toplamı, performans grafiği ve zekât hesabına girmez.
 */
class PozisyonDahilTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private Hesap $hesap2;

    private Varlik $gram;

    private Varlik $nakit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Evde']);
        $this->hesap2 = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Banka']);
        $this->gram = $this->varlik('GRAM', 'altin', 3000);
        $this->nakit = $this->varlik('TRY-NAKIT', 'nakit', null, kaynak: 'sabit');
        $this->actingAs($this->ben);
        $this->alis($this->hesap, $this->gram, 10, 2000); // değer 30.000, maliyet 20.000
        $this->alis($this->hesap, $this->nakit, 5000, 1);
        $this->alis($this->hesap2, $this->gram, 2, 2500); // değer 6.000
    }

    private function alis(Hesap $hesap, Varlik $varlik, float $miktar, float $fiyat): void
    {
        Islem::create([
            'user_id' => $hesap->user_id, 'hesap_id' => $hesap->id, 'varlik_id' => $varlik->id, 'tur' => IslemTuru::Alis,
            'miktar' => $miktar, 'birim_fiyat' => $fiyat, 'komisyon' => 0, 'tarih' => '2026-10-01',
        ]);
    }

    private function dahil(Hesap $hesap, Varlik $varlik, bool $dahil)
    {
        return $this->putJson("/api/v1/hesaplar/{$hesap->id}/pozisyonlar/{$varlik->id}", ['dahil' => $dahil]);
    }

    public function test_haric_satir_genel_toplam_hesap_toplami_dagilim_ve_varlik_toplamindan_duser_listede_kalir(): void
    {
        $ozet = $this->getJson('/api/v1/ozet')->assertOk();
        $ozet->assertJsonPath('toplam.deger', 41000)->assertJsonCount(3, 'pozisyonlar');
        $this->assertTrue(collect($ozet->json('pozisyonlar'))->every(fn (array $p): bool => $p['dahil']));

        $this->dahil($this->hesap, $this->gram, false)->assertOk()->assertJsonPath('dahil', false);

        $ozet = $this->getJson('/api/v1/ozet')->assertOk();
        $ozet->assertJsonPath('toplam.deger', 11000)            // 5.000 nakit + 6.000 (Banka'daki gram)
            ->assertJsonPath('toplam.maliyet', 5000 + 5000)     // yalnız dahil satırların maliyeti
            ->assertJsonCount(3, 'pozisyonlar');                 // hariç satır listede durur
        $haric = collect($ozet->json('pozisyonlar'))->firstWhere('dahil', false);
        $this->assertSame($this->gram->id, $haric['varlik']['id']);
        $this->assertSame($this->hesap->id, $haric['hesap_id']);
        $this->assertSame(30000.0, (float) $haric['deger']); // kendi değeri gösterilir, toplama girmez
        // Hariç satır sonda
        $this->assertFalse($ozet->json('pozisyonlar.2.dahil') === true);

        $evde = collect($ozet->json('hesaplar'))->firstWhere('ad', 'Evde');
        $this->assertSame(5000.0, (float) $evde['deger']);
        $this->assertSame(1, $evde['varlik_sayisi']);
        $this->assertSame(['altin' => 6000.0, 'nakit' => 5000.0], collect($ozet->json('dagilim'))->pluck('deger', 'tur')->map(fn ($d): float => (float) $d)->all());
        // Hesaplar arası varlık toplamı yalnız dahil olanlardan: gram 2 adet (Banka)
        $gram = collect($ozet->json('varliklar'))->firstWhere('varlik.id', $this->gram->id);
        $this->assertSame(2.0, (float) $gram['miktar']);

        // Hesap ucu aynı kural
        $this->getJson("/api/v1/hesaplar/{$this->hesap->id}")->assertOk()->assertJsonPath('hesap.deger', 5000)
            ->assertJsonPath('pozisyonlar.1.dahil', false);

        // Geri açılınca eski toplam; ayar satırı silinir
        $this->dahil($this->hesap, $this->gram, true)->assertOk()->assertJsonPath('dahil', true);
        $this->assertDatabaseCount('pozisyon_ayarlari', 0);
        $this->getJson('/api/v1/ozet')->assertJsonPath('toplam.deger', 41000);
    }

    public function test_performans_serisi_haric_satiri_icermez(): void
    {
        $this->dahil($this->hesap, $this->gram, false)->assertOk();
        $nokta = collect(app(PortfoyHesabi::class)->gecmis($this->ben, null, now()->subDays(5), now()))->last();
        $this->assertEqualsWithDelta(11000, $nokta['deger'], 0.01);
        $this->assertEqualsWithDelta(app(PortfoyHesabi::class)->hesapla($this->ben)['toplam']['deger'], $nokta['deger'], 0.01);
    }

    public function test_zekat_haric_satiri_hesaba_katmaz_nedenini_soyler(): void
    {
        $this->actingAs($this->ben);
        Fiyat::updateOrCreate(['varlik_id' => $this->gram->id], ['fiyat' => 3000, 'kaynak' => 'elle', 'zaman' => now()]);
        $once = $this->postJson('/api/v1/aile/zekat/hesapla')->assertOk();
        $this->assertSame(41000.0, (float) $once->json('brut'));

        $this->dahil($this->hesap, $this->gram, false)->assertOk();
        $r = $this->postJson('/api/v1/aile/zekat/hesapla')->assertOk();
        $this->assertSame(11000.0, (float) $r->json('brut'));
        $kalem = collect($r->json('kalemler'))->first(fn (array $k): bool => $k['hesap_ad'] === 'Evde' && $k['varlik_kod'] === 'GRAM');
        $this->assertFalse($kalem['dahil_edilen']);
        $this->assertSame('Yatırım toplamına dahil değil', $kalem['dislanma_nedeni']);
        $this->assertSame(0.0, (float) $kalem['hesaba_giren']);
    }

    public function test_olmayan_kayit_ve_dogrulama_reddedilir(): void
    {
        $baska = $this->varlik('THYAO');
        $this->dahil($this->hesap, $baska, false)->assertStatus(422); // hesapta işlem yok
        $this->putJson("/api/v1/hesaplar/{$this->hesap->id}/pozisyonlar/{$this->gram->id}", [])->assertStatus(422)->assertJsonValidationErrors('dahil');
    }

    public function test_baska_hane_degistiremez_ve_etkilenmez(): void
    {
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->dahil($this->hesap, $this->gram, false)->assertForbidden();
        $this->assertDatabaseCount('pozisyon_ayarlari', 0);

        // Kendi hanesindeki ayar, yabancının toplamını etkilemez
        $this->actingAs($this->ben);
        $this->dahil($this->hesap, $this->gram, false)->assertOk();
        $this->actingAs($yabanci)->getJson('/api/v1/ozet')->assertOk()->assertJsonPath('toplam.deger', 0)->assertJsonCount(0, 'pozisyonlar');
    }
}
