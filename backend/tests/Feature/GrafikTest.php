<?php

namespace Tests\Feature;

use App\Enums\EkstreDurumu;
use App\Models\Ekstre;
use App\Models\FaturaTuru;
use App\Models\Gelir;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faturalar / Kartlar / Gelirler dönem grafikleri: yılın 12 ayı, gelir kırılımı (tür, kişi), faturaların aylık
 * kazanca oranı (gelir 0 ise null), kart başına dönem sonu ödeme (onaylı ekstre dönem borcu), hane izolasyonu.
 */
class GrafikTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $uye;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $this->uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
    }

    private function gelir(string $donem, float $tutar, string $turAd = 'Maaş', ?int $uye = null): void
    {
        Gelir::create([
            'hane_id' => $this->ben->hane_id, 'uye_id' => $uye ?? $this->uye,
            'gelir_turu_id' => GelirTuru::where('hane_id', $this->ben->hane_id)->where('ad', $turAd)->value('id'),
            'duzen' => 'duzenli', 'tutar' => $tutar, 'para_birimi' => 'TRY', 'tl_tutar' => $tutar, 'tarih' => $donem.'-02', 'donem' => $donem,
        ]);
    }

    private function fatura(string $donem, string $turAd, float $tutar): void
    {
        $tur = FaturaTuru::whereNull('hane_id')->where('ad', $turAd)->value('id');
        $saglayici = $this->postJson('/api/v1/aile/saglayicilar', ['ad' => 'S'.$turAd.$donem, 'fatura_turu_id' => $tur, 'desenler' => []])->assertCreated()->json('saglayici.id');
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $saglayici, 'hizmet_donemi' => $donem, 'tutar' => $tutar, 'durum' => 'bekliyor'])->assertCreated();
    }

    public function test_gelir_kirilimi_toplam_tur_ve_kisi(): void
    {
        $es = HaneUyesi::create(['hane_id' => $this->ben->hane_id, 'ad' => 'Eşim', 'aktif' => true, 'sira' => 9]);
        $this->postJson('/api/v1/aile/gelir-turleri', ['ad' => 'Ek ders'])->assertCreated();
        $this->gelir('2026-11', 30000, 'Maaş');
        $this->gelir('2026-11', 2000, 'Ek ders');
        $this->gelir('2026-11', 25000, 'Maaş', $es->id);
        $this->gelir('2026-10', 28000, 'Maaş');

        $r = $this->getJson('/api/v1/aile/grafikler?yil=2026')->assertOk();
        $this->assertCount(12, $r->json('etiketler'));
        $this->assertCount(12, $r->json('gelir.toplam'));
        $this->assertEquals(57000, $r->json('gelir.toplam.10'));
        $this->assertEquals(28000, $r->json('gelir.toplam.9'));
        $maas = collect($r->json('gelir.turler'))->firstWhere('ad', 'Maaş');
        $this->assertEquals(55000, $maas['toplam'][10]);
        $kisiAdlari = collect($maas['kisiler'])->pluck('ad')->sort()->values()->all();
        $this->assertContains('Eşim', $kisiAdlari);
        $esMaas = collect($maas['kisiler'])->firstWhere('uye_id', $es->id);
        $this->assertEquals(25000, $esMaas['toplam'][10]);
        $ekDers = collect($r->json('gelir.turler'))->firstWhere('ad', 'Ek ders');
        $this->assertEquals(2000, $ekDers['toplam'][10]);
        $benim = collect($r->json('gelir.kisiler'))->firstWhere('uye_id', $this->uye);
        $this->assertEquals(32000, $benim['toplam'][10]);
    }

    public function test_faturalarin_aylik_kazanca_orani_gelir_yoksa_null(): void
    {
        $this->gelir('2026-11', 40000);
        $this->fatura('2026-11', 'Elektrik', 1000);
        $this->fatura('2026-11', 'Su', 400);
        $this->fatura('2026-03', 'Elektrik', 700);   // o ay gelir yok

        $r = $this->getJson('/api/v1/aile/grafikler?yil=2026')->assertOk();
        $this->assertEquals(1400, $r->json('faturalar.toplam.10'));
        $this->assertEquals(3.5, $r->json('faturalar.toplam_oran.10'));
        $elektrik = collect($r->json('faturalar.turler'))->firstWhere('ad', 'Elektrik');
        $this->assertEquals(1000, $elektrik['tutar'][10]);
        $this->assertEquals(2.5, $elektrik['oran'][10]);
        $this->assertEquals(700, $elektrik['tutar'][2]);
        $this->assertNull($elektrik['oran'][2]);
        $this->assertNull($r->json('faturalar.toplam_oran.2'));
    }

    public function test_kart_donem_sonu_odemesi_onayli_ekstre_taslak_sayilmaz(): void
    {
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $a = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $this->uye, 'ad' => 'Bonus', 'renk' => 'sky'])->assertCreated()->json('kart.id');
        $b = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $this->uye, 'ad' => 'Maximum'])->json('kart.id');
        $ekstre = fn (int $kart, string $kesim, string $borc, EkstreDurumu $durum) => Ekstre::create([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $kart, 'durum' => $durum, 'kesim_tarihi' => $kesim, 'donem_borcu' => $borc,
        ]);
        $ekstre($a, '2026-11-10', '4500.00', EkstreDurumu::Onaylandi);
        $ekstre($b, '2026-11-12', '1500.50', EkstreDurumu::Onaylandi);
        $ekstre($a, '2026-10-10', '3000.00', EkstreDurumu::Onaylandi);
        $ekstre($b, '2026-10-12', '9999.00', EkstreDurumu::Taslak);

        $r = $this->getJson('/api/v1/aile/grafikler?yil=2026')->assertOk();
        $this->assertEquals(6000.5, $r->json('kartlar.toplam.10'));
        $this->assertEquals(3000, $r->json('kartlar.toplam.9'));
        $bonus = collect($r->json('kartlar.kartlar'))->firstWhere('kart_id', $a);
        $this->assertSame('Bonus', $bonus['ad']);
        $this->assertSame('sky', $bonus['renk']);
        $this->assertEquals(4500, $bonus['tutar'][10]);
        $this->assertEquals(3000, $bonus['tutar'][9]);
        $this->assertEquals(0, collect($r->json('kartlar.kartlar'))->firstWhere('kart_id', $b)['tutar'][9]);
    }

    public function test_baska_hane_goremez_ve_yil_dogrulanir(): void
    {
        $this->gelir('2026-11', 30000);
        $this->fatura('2026-11', 'Su', 400);
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $r = $this->getJson('/api/v1/aile/grafikler?yil=2026')->assertOk();
        $this->assertEquals(0, array_sum($r->json('gelir.toplam')));
        $this->assertEquals(0, array_sum($r->json('faturalar.toplam')));
        $this->assertSame([], $r->json('gelir.turler'));
        $this->assertSame([], $r->json('faturalar.turler'));
        $this->assertSame([], $r->json('kartlar.kartlar'));
        $this->getJson('/api/v1/aile/grafikler?yil=1900')->assertJsonValidationErrors('yil');
    }
}
