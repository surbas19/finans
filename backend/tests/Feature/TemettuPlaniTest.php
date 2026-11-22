<?php

namespace Tests\Feature;

use App\Models\Hesap;
use App\Models\Islem;
use App\Models\TemettuPlani;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Önceden planlanan temettü: lot ödeme gününde hesaptaki adet, net = lot × hisse başı × (1 − stopaj); Özet şeridi
 * (3 gün kala / günü geçmiş); yatınca gerçek temettü + hesaba nakit; hesabı görmeyen göremez.
 */
class TemettuPlaniTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private int $hisse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Yatırım']);
        $this->hisse = $this->varlik('THYAO', 'hisse', 337)->id;
        $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse, 'tur' => 'alis', 'miktar' => 500, 'birim_fiyat' => 100, 'komisyon' => 0, 'tarih' => '2026-01-10']);
    }

    /**
     * @param  array<string, mixed>  $alan
     * @return array<string, mixed>
     */
    private function plan(array $alan = []): array
    {
        return ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse, 'tarih' => '2026-11-25', 'hisse_basi' => 9.44, 'stopaj_yuzde' => 10, ...$alan];
    }

    public function test_plan_lot_brut_stopaj_net_ve_oran_hesaplanir(): void
    {
        $r = $this->postJson('/api/v1/temettu-planlari', $this->plan())->assertCreated();
        $p = $r->json('plan');
        $this->assertEquals(500, $p['lot']);
        $this->assertEquals(4720, $p['brut']);
        $this->assertEquals(472, $p['stopaj']);
        $this->assertEquals(4248, $p['net']);
        $this->assertEquals(2.8, $p['oran_yuzde']);
        $this->assertSame(3, $p['gun_kaldi']);
        $this->assertSame('bekliyor', $p['durum']);

        $liste = $this->getJson('/api/v1/temettu-planlari')->assertOk();
        $this->assertCount(1, $liste->json('bekleyenler'));
        $this->assertEquals(4248, $liste->json('toplam_net'));
        // Plan portföy toplamına / zekâta karışmaz: hiçbir işlem yazılmadı
        $this->assertSame(1, Islem::count());
    }

    public function test_dogrulama_hisse_degil_hesapta_yok_gecmis_tarih(): void
    {
        $nakit = $this->varlik('USDX', 'doviz', 30)->id;
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['varlik_id' => $nakit]))->assertStatus(422)->assertJsonValidationErrors('varlik_id');
        $yok = $this->varlik('ASELS', 'hisse', 100)->id;
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['varlik_id' => $yok]))->assertStatus(422)->assertJsonValidationErrors('varlik_id');
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-10']))->assertStatus(422)->assertJsonValidationErrors('tarih');
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['hisse_basi' => 0]))->assertStatus(422)->assertJsonValidationErrors('hisse_basi');
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['stopaj_yuzde' => 101]))->assertStatus(422)->assertJsonValidationErrors('stopaj_yuzde');
    }

    public function test_secenekler_elde_hisse_olan_hesap_hisse_cifti_lot_ve_fiyat(): void
    {
        $this->getJson('/api/v1/temettu-planlari/secenekler')->assertOk()
            ->assertJsonCount(1, 'secenekler')->assertJsonPath('secenekler.0.kod', 'THYAO')
            ->assertJsonPath('secenekler.0.lot', 500)->assertJsonPath('secenekler.0.hesap_ad', 'Yatırım');
    }

    public function test_serit_uc_gun_kala_ve_gunu_gecmis_icin_gelir_uzak_planlar_gelmez(): void
    {
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-25']))->assertCreated();
        $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-12-20']))->assertCreated();
        $serit = $this->getJson('/api/v1/temettu-planlari')->json('serit');
        $this->assertCount(1, $serit);
        $this->assertSame('2026-11-25', $serit[0]['tarih']);

        // Günü geçince hâlâ bekliyorsa şeritte ("yattı mı?")
        Carbon::setTestNow('2026-11-27 10:00:00');
        $serit = $this->getJson('/api/v1/temettu-planlari')->json('serit');
        $this->assertCount(1, $serit);
        $this->assertSame(-2, $serit[0]['gun_kaldi']);
    }

    public function test_yatti_gercek_temettu_ve_nakit_yazar_plan_yatti_olur_ikinci_kez_olmaz(): void
    {
        $id = $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-22']))->json('plan.id');
        $this->postJson("/api/v1/temettu-planlari/{$id}/yatti")->assertOk()->assertJsonPath('plan.durum', 'yatti');

        $this->assertDatabaseHas('islemler', ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse, 'tur' => 'temettu', 'miktar' => 4248, 'komisyon' => 472, 'bag_turu' => 'temettu']);
        $this->assertDatabaseHas('islemler', ['hesap_id' => $this->hesap->id, 'tur' => 'alis', 'miktar' => 4248, 'bag_turu' => 'temettu']);
        $this->assertNotNull(TemettuPlani::find($id)->islem_id);
        $this->postJson("/api/v1/temettu-planlari/{$id}/yatti")->assertStatus(422);
        $liste = $this->getJson('/api/v1/temettu-planlari')->json();
        $this->assertCount(0, $liste['bekleyenler']);
        $this->assertCount(1, $liste['yatanlar']);
    }

    public function test_ileri_tarihli_plan_yatti_bugunun_tarihiyle_yazilir_net_banka_tutariyla_ezilebilir(): void
    {
        $id = $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-25']))->json('plan.id');
        $this->postJson("/api/v1/temettu-planlari/{$id}/yatti", ['net' => 4000])->assertOk();
        $this->assertDatabaseHas('islemler', ['tur' => 'temettu', 'miktar' => 4000, 'komisyon' => 720, 'tarih' => '2026-11-22 00:00:00']);
    }

    public function test_yatti_hesapta_hisse_kalmadiysa_reddedilir(): void
    {
        $id = $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-22']))->json('plan.id');
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->hisse, 'tur' => 'satis', 'miktar' => 500, 'birim_fiyat' => 100, 'komisyon' => 0, 'tarih' => '2026-11-20']);
        $this->postJson("/api/v1/temettu-planlari/{$id}/yatti")->assertStatus(422)->assertJsonValidationErrors('tarih');
        $this->assertSame('bekliyor', TemettuPlani::find($id)->durum);
    }

    public function test_guncelle_sil(): void
    {
        $id = $this->postJson('/api/v1/temettu-planlari', $this->plan())->json('plan.id');
        $this->putJson("/api/v1/temettu-planlari/{$id}", ['tarih' => '2026-12-01', 'hisse_basi' => 10, 'stopaj_yuzde' => 15])->assertOk()
            ->assertJsonPath('plan.tarih', '2026-12-01')->assertJsonPath('plan.stopaj_yuzde', 15);
        $this->deleteJson("/api/v1/temettu-planlari/{$id}")->assertOk();
        $this->assertDatabaseMissing('temettu_planlari', ['id' => $id]);
    }

    public function test_baska_hane_gormez_degistiremez_yatti_diyemez_ve_hesabina_plan_giremez(): void
    {
        $id = $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-22']))->json('plan.id');
        $baska = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($baska);
        $this->actingAs($baska);
        $this->getJson('/api/v1/temettu-planlari')->assertOk()->assertJsonCount(0, 'bekleyenler')->assertJsonCount(0, 'serit');
        $this->getJson('/api/v1/temettu-planlari/secenekler')->assertOk()->assertJsonCount(0, 'secenekler');
        $this->putJson("/api/v1/temettu-planlari/{$id}", ['tarih' => '2026-12-01', 'hisse_basi' => 1, 'stopaj_yuzde' => 10])->assertForbidden();
        $this->deleteJson("/api/v1/temettu-planlari/{$id}")->assertForbidden();
        $this->postJson("/api/v1/temettu-planlari/{$id}/yatti")->assertForbidden();
        $this->postJson('/api/v1/temettu-planlari', $this->plan())->assertStatus(422)->assertJsonValidationErrors('hesap_id');
    }

    public function test_ayni_haneden_es_plani_gorur_ve_yatti_diyebilir(): void
    {
        $id = $this->postJson('/api/v1/temettu-planlari', $this->plan(['tarih' => '2026-11-22']))->json('plan.id');
        $es = User::factory()->create();
        $es->forceFill(['hane_id' => $this->ben->hane_id])->save();
        $this->actingAs($es);
        $this->getJson('/api/v1/temettu-planlari')->assertOk()->assertJsonCount(1, 'bekleyenler');
        $this->postJson("/api/v1/temettu-planlari/{$id}/yatti")->assertOk();
    }
}
