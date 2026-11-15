<?php

namespace Tests\Feature;

use App\Enums\IslemTuru;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Models\ZekatOdemesi;
use App\Models\ZekatYili;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Zekât ödemeleri "Ramazan'dan Ramazan'a" yıl bazında: dönemler, verilen / kalan / fazla, ödemenin tarihe göre
 * kesinleşmiş yıla otomatik bağlanması, hane izolasyonu.
 */
class ZekatDonemTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private Varlik $nakit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Yatırım']);
        $this->nakit = $this->varlik('TRY-NAKIT', 'nakit', null, kaynak: 'sabit');
        $this->varlik('GRAM', 'altin', 6300);
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->nakit->id, 'tur' => IslemTuru::Alis, 'miktar' => 600000, 'birim_fiyat' => 1, 'komisyon' => 0, 'tarih' => '2026-01-01']);
        $this->actingAs($this->ben);
    }

    private function miladiAyar(): void
    {
        $this->putJson('/api/v1/aile/zekat/ayar', ['zekat_gunu_turu' => 'miladi', 'miladi_ay' => 3, 'miladi_gun' => 1])->assertOk();
    }

    private function odeme(string $tarih, float $tutar, ?int $yilId = null): int
    {
        return $this->postJson('/api/v1/aile/zekat/odemeler', ['tarih' => $tarih, 'tutar' => $tutar, 'alici' => 'Vakıf', 'zekat_yili_id' => $yilId])->assertCreated()->json('odeme.id');
    }

    private function kesinlesmisYil(string $gun): int
    {
        $id = $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => $gun])->assertCreated()->json('yil.id');
        $this->postJson("/api/v1/aile/zekat/yillar/{$id}/kesinlestir")->assertOk();

        return $id;
    }

    public function test_odemeler_zekat_gunune_gore_yillara_duser_icinde_bulunulan_yil_bos_da_listelenir(): void
    {
        $this->miladiAyar();
        $this->odeme('2026-04-10', 3000);
        $this->odeme('2026-09-01', 2000);
        $this->odeme('2026-02-10', 1000); // bir önceki zekât yılı (1 Mart 2025 → 1 Mart 2026)

        $d = collect($this->getJson('/api/v1/aile/zekat/donemler')->assertOk()->json('donemler'));
        $this->assertCount(2, $d);
        $guncel = $d->firstWhere('guncel', true);
        $this->assertSame('2026-03-01', $guncel['baslangic']);
        $this->assertSame('2027-03-01', $guncel['bitis']);
        $this->assertEquals(5000, $guncel['verilen']);
        $this->assertCount(2, $guncel['odemeler']);
        $this->assertNull($guncel['gereken']);   // bu yıl için zekât henüz hesaplanmadı
        $this->assertNull($guncel['kalan']);
        $onceki = $d->firstWhere('baslangic', '2025-03-01');
        $this->assertEquals(1000, $onceki['verilen']);
        $this->assertFalse($onceki['guncel']);
        $this->assertSame('2026-03-01', $d[0]['baslangic']); // en yeni üstte

        // Ödemesiz bile içinde bulunulan yıl listelenir
        ZekatOdemesi::query()->delete();
        $d = $this->getJson('/api/v1/aile/zekat/donemler')->json('donemler');
        $this->assertCount(1, $d);
        $this->assertEquals(0, $d[0]['verilen']);
    }

    public function test_kesinlesmis_yil_gereken_verilen_kalan_ve_otomatik_baglama(): void
    {
        $this->miladiAyar();
        $yil = $this->kesinlesmisYil('2026-03-01'); // 600.000 nakit → %2,5 = 15.000
        $this->assertEquals(15000, ZekatYili::find($yil)->zekat);

        $a = $this->odeme('2026-05-05', 6000);         // yıl seçilmedi → tarihe göre 2026 yılına bağlanır
        $this->assertSame($yil, ZekatOdemesi::find($a)->zekat_yili_id);
        $this->odeme('2026-10-01', 4000);

        $d = collect($this->getJson('/api/v1/aile/zekat/donemler')->json('donemler'))->firstWhere('baslangic', '2026-03-01');
        $this->assertEquals(15000, $d['gereken']);
        $this->assertEquals(10000, $d['verilen']);
        $this->assertEquals(5000, $d['kalan']);
        $this->assertEquals(0, $d['fazla']);
        $this->assertSame($yil, $d['yil']['id']);
        $this->assertSame('kesinlesti', $d['yil']['durum']);
        // Yıl kaydındaki ödenen / kalan da aynı (bağlı)
        $this->getJson("/api/v1/aile/zekat/yillar/{$yil}")->assertJsonPath('yil.odenen', 10000)->assertJsonPath('yil.kalan', 5000);

        // Fazla verilirse kalan 0, fazla görünür
        $this->odeme('2026-11-01', 7000);
        $d = collect($this->getJson('/api/v1/aile/zekat/donemler')->json('donemler'))->firstWhere('baslangic', '2026-03-01');
        $this->assertEquals(0, $d['kalan']);
        $this->assertEquals(2000, $d['fazla']);

        // 1 Mart'tan önceki ödeme önceki yıla düşer; o yıl için kesinleşmiş kayıt yoksa bağsız kalır
        $this->assertNull(ZekatOdemesi::find($this->odeme('2026-02-28', 500))->zekat_yili_id);
        $onceki = collect($this->getJson('/api/v1/aile/zekat/donemler')->json('donemler'))->firstWhere('baslangic', '2025-03-01');
        $this->assertEquals(500, $onceki['verilen']);
    }

    public function test_hicri_varsayilan_ayar_ramazan_yili_etiketi_ve_yil_uzunlugu(): void
    {
        $this->odeme('2026-11-01', 1000);
        $d = collect($this->getJson('/api/v1/aile/zekat/donemler')->assertOk()->json('donemler'));
        $guncel = $d->firstWhere('guncel', true);
        $this->assertStringContainsString('Ramazan', $guncel['etiket']);
        $this->assertTrue($guncel['hicri']);
        $this->assertLessThanOrEqual('2026-11-15', $guncel['baslangic']);
        $this->assertGreaterThan('2026-11-15', $guncel['bitis']);
        $this->assertEquals(1000, $guncel['verilen']);
        $gun = Carbon::parse($guncel['baslangic'])->diffInDays(Carbon::parse($guncel['bitis']));
        $this->assertGreaterThanOrEqual(353, $gun);
        $this->assertLessThanOrEqual(356, $gun);
    }

    public function test_baska_hane_donemleri_goremez_ve_odemesini_degistiremez(): void
    {
        $this->miladiAyar();
        $id = $this->odeme('2026-04-10', 3000);

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $d = $this->getJson('/api/v1/aile/zekat/donemler')->assertOk()->json('donemler');
        $this->assertEquals(0, collect($d)->sum('verilen'));
        $this->deleteJson("/api/v1/aile/zekat/odemeler/{$id}")->assertForbidden();
    }
}
