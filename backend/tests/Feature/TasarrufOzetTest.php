<?php

namespace Tests\Feature;

use App\Models\DuzenliGider;
use App\Models\Gelir;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\Hesap;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Bütçe → Tasarruf sekmesi: yılın 12 ayı, gelir / gider / tasarruf ve oranlar, ayın tasarruf kalemleri
 * (başkasına verilen gün alımı dahil), hane izolasyonu.
 */
class TasarrufOzetTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Evde']);
        $this->actingAs($this->ben);
    }

    private function gelir(string $donem, float $tutar): void
    {
        $uye = HaneUyesi::where('user_id', $this->ben->id)->first();
        Gelir::create([
            'hane_id' => $this->ben->hane_id, 'uye_id' => $uye->id, 'gelir_turu_id' => GelirTuru::where('hane_id', $this->ben->hane_id)->value('id'),
            'duzen' => 'duzenli', 'tutar' => $tutar, 'para_birimi' => 'TRY', 'tl_tutar' => $tutar, 'tarih' => $donem.'-02', 'donem' => $donem,
        ]);
    }

    private function gider(string $donem, float $tutar): void
    {
        DuzenliGider::create(['hane_id' => $this->ben->hane_id, 'aciklama' => 'Kira', 'tutar' => $tutar, 'tarih' => $donem.'-05', 'donem' => $donem]);
    }

    public function test_aylik_gelir_gider_tasarruf_oranlari_ve_kalemler_verilen_alim_dahil(): void
    {
        $altin = $this->varlik('GRAM', 'altin', 3000);
        $this->gelir('2026-11', 50000);
        $this->gider('2026-11', 20000);
        $this->gelir('2026-10', 40000);
        // Kasım: hesaba alınan 10.000 + başkasına verilen 6.000 (hesaba yazılmaz)
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'miktar' => 2, 'birim_fiyat' => 5000, 'tarih' => '2026-11-03'])->assertCreated();
        $gun = $this->postJson('/api/v1/aile/tasarruf-gunleri', ['ad' => 'Mahalle günü', 'hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'miktar' => 2, 'ay_gunu' => 10, 'baslangic' => '2026-09-01'])->json('gun.id');
        $this->postJson('/api/v1/aile/tasarruflar', ['varlik_id' => $altin->id, 'miktar' => 2, 'birim_fiyat' => 3000, 'tarih' => '2026-11-10', 'gun_id' => $gun, 'not' => 'Ayşe teyze'])->assertCreated();

        $r = $this->getJson('/api/v1/aile/tasarruf-ozeti?yil=2026')->assertOk();
        $this->assertCount(12, $r->json('aylar'));
        $kasim = collect($r->json('aylar'))->firstWhere('donem', '2026-11');
        $this->assertSame(50000, $kasim['gelir']);
        $this->assertSame(20000, $kasim['gider']);
        $this->assertSame(16000, $kasim['tasarruf']);
        $this->assertSame(14000, $kasim['kalan']);                // 50.000 − 20.000 − 16.000
        $this->assertEquals(40.0, $kasim['gider_orani']);
        $this->assertEquals(32.0, $kasim['tasarruf_orani']);
        $this->assertEquals(28.0, $kasim['kalan_orani']);
        $this->assertCount(2, $kasim['kalemler']);
        $verilen = collect($kasim['kalemler'])->firstWhere('hesaba_eklendi', false);
        $this->assertSame('Ayşe teyze', $verilen['not']);
        $this->assertSame('Mahalle günü', $verilen['gun_ad']);
        $this->assertEqualsWithDelta(2.0, $verilen['miktar'], 1e-9);
        $this->assertSame('T-'.$verilen['id'], $verilen['no']);
        // Hesaba alınan kalemde işlem numarası ve kayıt zamanı
        $alinan = collect($kasim['kalemler'])->firstWhere('hesaba_eklendi', true);
        $this->assertMatchesRegularExpression('/^İ-\d+$/', $alinan['islem_no']);
        $this->assertNotNull($alinan['islem_zamani']);
        // Gelir olmayan ay: oranlar null; tasarruf yok
        $ocak = collect($r->json('aylar'))->firstWhere('donem', '2026-01');
        $this->assertNull($ocak['tasarruf_orani']);
        $this->assertSame([], $ocak['kalemler']);
        // Yıl toplamı: gelir 90.000, gider 20.000, tasarruf 16.000
        $this->assertSame(90000, $r->json('toplam.gelir'));
        $this->assertSame(16000, $r->json('toplam.tasarruf'));
        $this->assertEquals(17.8, $r->json('toplam.tasarruf_orani')); // 16.000 / 90.000
    }

    public function test_baska_hane_gormez_ve_yil_dogrulanir(): void
    {
        $altin = $this->varlik('GRAM', 'altin', 3000);
        $this->gelir('2026-11', 50000);
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $altin->id, 'miktar' => 1, 'birim_fiyat' => 3000, 'tarih' => '2026-11-03'])->assertCreated();

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $r = $this->actingAs($yabanci)->getJson('/api/v1/aile/tasarruf-ozeti?yil=2026')->assertOk();
        $this->assertSame(0, $r->json('toplam.gelir'));
        $this->assertSame(0, $r->json('toplam.tasarruf'));
        $this->getJson('/api/v1/aile/tasarruf-ozeti?yil=abc')->assertStatus(422);
        $this->getJson('/api/v1/aile/tasarruf-ozeti')->assertOk()->assertJsonPath('yil', 2026);
    }
}
