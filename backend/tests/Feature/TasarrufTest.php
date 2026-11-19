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

    public function test_tasarruf_duzeltilir_bagli_alis_da_guncellenir(): void
    {
        $this->actingAs($this->ben);
        $altin = $this->varlik('GRAM', 'altin', 3000);
        $dolar = $this->varlik('USD', 'doviz', 40);
        $banka = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Banka']);
        $id = $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'miktar' => 2, 'birim_fiyat' => 3000, 'tarih' => '2026-11-10'])->json('tasarruf.id');

        // Miktar, fiyat, tarih (önceki aya), varlık ve hesap değişir: alış da aynen
        $this->putJson("/api/v1/aile/tasarruflar/{$id}", [
            'hesap_id' => $banka->id, 'varlik_id' => $dolar->id, 'miktar' => 100, 'birim_fiyat' => 40.5, 'komisyon' => 5, 'tarih' => '2026-10-28', 'not' => 'düzeltme',
        ])->assertOk()->assertJsonPath('tasarruf.donem', '2026-10')->assertJsonPath('tasarruf.tutar', 4055)
            ->assertJsonPath('tasarruf.hesap_ad', 'Banka')->assertJsonPath('message', 'Tasarruf düzeltildi.');

        $islem = Islem::sole();
        $this->assertSame([$banka->id, $dolar->id, 100.0, 40.5, 5.0, '2026-10-28', 'düzeltme'], [$islem->hesap_id, $islem->varlik_id, $islem->miktar, $islem->birim_fiyat, $islem->komisyon, $islem->tarih->toDateString(), $islem->not]);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('tasarruf.toplam', 0);
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('tasarruf.toplam', 4055);
    }

    public function test_duzeltme_sonraki_satisi_bozamaz_ve_baska_hane_duzeltemez(): void
    {
        $this->actingAs($this->ben);
        $hisse = $this->varlik('THYAO');
        $id = $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 10, 'birim_fiyat' => 100, 'tarih' => '2026-11-01'])->json('tasarruf.id');
        $this->postJson('/api/v1/islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'tur' => 'satis', 'miktar' => 8, 'birim_fiyat' => 120, 'tarih' => '2026-11-05'])->assertCreated();

        $govde = ['hesap_id' => $this->hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 5, 'birim_fiyat' => 100, 'tarih' => '2026-11-01'];
        $this->putJson("/api/v1/aile/tasarruflar/{$id}", $govde)->assertStatus(422)->assertJsonValidationErrors('miktar');
        // Başka hesaba taşımak da eski hesaptaki satışı bozar
        $diger = Hesap::factory()->create(['user_id' => $this->ben->id]);
        $this->putJson("/api/v1/aile/tasarruflar/{$id}", [...$govde, 'miktar' => 10, 'hesap_id' => $diger->id])->assertStatus(422);
        $this->assertSame(10.0, Islem::where('tur', 'alis')->sole()->miktar);
        $this->putJson("/api/v1/aile/tasarruflar/{$id}", [...$govde, 'miktar' => 9])->assertOk();

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci)->putJson("/api/v1/aile/tasarruflar/{$id}", [...$govde, 'miktar' => 9])->assertForbidden();
    }
}
