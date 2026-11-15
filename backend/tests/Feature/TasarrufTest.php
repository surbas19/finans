<?php

namespace Tests\Feature;

use App\Models\Gelir;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Tasarruf yap: portföy hesabına alış işlemi + bütçede ayın tasarrufu (gider değil; Kalan = Gelir − Gider − Tasarruf).
 */
class TasarrufTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Evde']);
    }

    public function test_tasarruf_portfoye_alis_yazar_ayin_tasarrufu_olur_ve_kalani_dusurur(): void
    {
        $this->actingAs($this->ben);
        $altin = $this->varlik('GRAM', 'altin', 3000);
        $uye = HaneUyesi::where('user_id', $this->ben->id)->first();
        Gelir::create([
            'hane_id' => $this->ben->hane_id, 'uye_id' => $uye->id, 'gelir_turu_id' => GelirTuru::where('hane_id', $this->ben->hane_id)->value('id'),
            'duzen' => 'duzenli', 'tutar' => 50000, 'para_birimi' => 'TRY', 'tl_tutar' => 50000, 'tarih' => '2026-11-02', 'donem' => '2026-11',
        ]);

        $id = $this->postJson('/api/v1/aile/tasarruflar', [
            'hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'miktar' => 2.5, 'birim_fiyat' => 3000.4, 'komisyon' => 10, 'tarih' => '2026-11-10', 'uye_id' => $uye->id,
        ])->assertCreated()->assertJsonPath('tasarruf.donem', '2026-11')->assertJsonPath('tasarruf.tutar', 7511)
            ->assertJsonPath('tasarruf.hesap_ad', 'Evde')->assertJsonPath('tasarruf.varlik_ad', 'GRAM adı')->json('tasarruf.id');

        // Portföyde gerçek alış işlemi
        $islem = Islem::where('hesap_id', $this->hesap->id)->first();
        $this->assertSame(['alis', 2.5, 3000.4, 10.0], [$islem->tur->value, $islem->miktar, $islem->birim_fiyat, $islem->komisyon]);

        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()
            ->assertJsonPath('gider.toplam', 0)->assertJsonPath('tasarruf.toplam', 7511)->assertJsonCount(1, 'tasarruf.kalemler')
            ->assertJsonPath('fark', 50000)->assertJsonPath('kalan', 42489);
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('tasarruf.toplam', 0)->assertJsonPath('kalan', 0);
        $this->getJson('/api/v1/aile/tasarruflar?donem=2026-11')->assertJsonPath('tasarruf.toplam', 7511);

        $this->deleteJson("/api/v1/aile/tasarruflar/{$id}")->assertOk();
        $this->assertSame(0, Islem::count());
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('tasarruf.toplam', 0);
    }

    public function test_nakit_birim_fiyatsiz_girer_ve_ileri_tarih_bes_reddedilir(): void
    {
        $this->actingAs($this->ben);
        $nakit = $this->varlik('TRY-NAKIT', 'nakit', null);
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $nakit->id, 'miktar' => 5000, 'tarih' => '2026-11-12'])
            ->assertCreated()->assertJsonPath('tasarruf.tutar', 5000);
        $this->assertSame(1.0, Islem::first()->birim_fiyat);

        $hisse = $this->varlik('THYAO');
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 1, 'tarih' => '2026-11-12'])
            ->assertStatus(422)->assertJsonValidationErrors('birim_fiyat');
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 1, 'birim_fiyat' => 10, 'tarih' => '2026-11-20'])
            ->assertStatus(422)->assertJsonValidationErrors('tarih');
        $bes = $this->varlik('BES1', 'bes', null);
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $bes->id, 'miktar' => 100, 'tarih' => '2026-11-12'])
            ->assertStatus(422)->assertJsonValidationErrors('varlik_id');
    }

    public function test_satisi_bozacak_tasarruf_silinemez(): void
    {
        $this->actingAs($this->ben);
        $hisse = $this->varlik('THYAO');
        $id = $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 10, 'birim_fiyat' => 100, 'tarih' => '2026-11-01'])->json('tasarruf.id');
        $this->postJson('/api/v1/islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'tur' => 'satis', 'miktar' => 10, 'birim_fiyat' => 120, 'tarih' => '2026-11-05'])->assertCreated();
        $this->deleteJson("/api/v1/aile/tasarruflar/{$id}")->assertStatus(422)->assertJsonValidationErrors('tasarruf');
        $this->assertSame(2, Islem::count());
    }

    public function test_es_hesabina_yazilir_baska_hane_goremez_ve_hesabina_yazamaz(): void
    {
        $this->actingAs($this->ben);
        $hisse = $this->varlik('THYAO');
        $id = $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 1, 'birim_fiyat' => 100, 'tarih' => '2026-11-01'])->json('tasarruf.id');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/tasarruflar?donem=2026-11')->assertJsonPath('tasarruf.toplam', 0)->assertJsonCount(0, 'tasarruf.kalemler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('tasarruf.toplam', 0);
        $this->deleteJson("/api/v1/aile/tasarruflar/{$id}")->assertForbidden();
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 1, 'birim_fiyat' => 100, 'tarih' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('hesap_id');
        $this->assertSame(1, Islem::count());
    }
}
