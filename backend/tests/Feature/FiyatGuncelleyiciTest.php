<?php

namespace Tests\Feature;

use App\Fiyat\FiyatGuncelleyici;
use App\Fiyat\Katalog;
use App\Models\Fiyat;
use App\Models\FiyatGecmisi;
use App\Models\KaynakDurumu;
use App\Models\Varlik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiyatGuncelleyiciTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function truncgil(): array
    {
        return [
            'Update_Date' => now()->format('Y-m-d H:i:s'),
            'USD' => ['Buying' => 50.36, 'Selling' => 50.37, 'Change' => 0.22, 'Type' => 'Currency'],
            'EUR' => ['Buying' => '55,68', 'Selling' => '55,77', 'Change' => '%0,06', 'Type' => 'Currency'],
            'GRA' => ['Buying' => 6309, 'Selling' => 6310.01, 'Change' => 0.79, 'Type' => 'Gold', 'Name' => 'GRAMALTIN'],
            'CEYREKALTIN' => ['Buying' => 10275, 'Selling' => 10377, 'Change' => 0.57, 'Type' => 'Gold'],
            'ONS' => ['Buying' => 0, 'Selling' => 0, 'Change' => 0.56, 'Type' => 'Gold'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function yahoo(float $fiyat, float $onceki): array
    {
        return ['chart' => ['result' => [[
            'meta' => ['currency' => 'USD', 'symbol' => 'GC=F', 'regularMarketPrice' => $fiyat, 'chartPreviousClose' => $onceki, 'regularMarketTime' => now()->getTimestamp(), 'longName' => 'Gold'],
            'timestamp' => [now()->subDays(2)->getTimestamp(), now()->subDay()->getTimestamp()],
            'indicators' => ['quote' => [['close' => [3900.5, 3910.25]]]],
        ]]]];
    }

    public function test_kaynaklar_fiyat_ve_gunun_kapanisini_yazar_bozulan_kaynak_obururlerini_durdurmaz(): void
    {
        Katalog::esitle();
        Http::fake([
            'finans.truncgil.com/*' => Http::response($this->truncgil()),
            'query1.finance.yahoo.com/*' => Http::response($this->yahoo(3917.5, 3895)),
            'api.binance.com/*' => Http::response('bakımda', 503),
        ]);

        $rapor = app(FiyatGuncelleyici::class)->guncelle();

        $this->assertSame(4, $rapor['truncgil']); // USD, EUR, GRAM, CEYREK (ONS 0 atlanır, diğerleri listede yok)
        $this->assertSame(1, $rapor['yahoo']);
        $this->assertStringStartsWith('hata:', (string) $rapor['binance']);

        $gram = Fiyat::find(Varlik::where('kod', 'GRAM')->value('id'));
        $this->assertEqualsWithDelta(6309, $gram->fiyat, 1e-6);
        $this->assertEqualsWithDelta(6310.01, $gram->satis, 1e-6);
        $this->assertEqualsWithDelta(0.79, $gram->degisim_yuzde, 1e-6);
        $eur = Fiyat::find(Varlik::where('kod', 'EUR')->value('id'));
        $this->assertEqualsWithDelta(55.68, $eur->fiyat, 1e-6);
        $this->assertEqualsWithDelta(0.06, $eur->degisim_yuzde, 1e-6);
        $ons = Fiyat::find(Varlik::where('kod', 'ONS')->value('id'));
        $this->assertEqualsWithDelta(3917.5, $ons->fiyat, 1e-6);
        $this->assertEqualsWithDelta(3895, $ons->onceki_kapanis, 1e-6);

        $this->assertDatabaseHas('fiyat_gecmisi', ['varlik_id' => $gram->varlik_id, 'tarih' => now()->toDateString()]);

        $truncgil = KaynakDurumu::find('truncgil');
        $this->assertNotNull($truncgil->son_basari);
        $this->assertStringContainsString('Fiyatı gelmeyen: HAS', (string) $truncgil->son_hata);
        $binance = KaynakDurumu::find('binance');
        $this->assertNull($binance->son_basari);
        $this->assertSame(1, $binance->art_arda_hata);
        $this->assertStringContainsString('503', (string) $binance->son_hata);

        // Aynı gün ikinci güncelleme günün satırını değiştirir, yeni satır açmaz
        app(FiyatGuncelleyici::class)->guncelle('truncgil');
        $this->assertSame(1, FiyatGecmisi::where('varlik_id', $gram->varlik_id)->count());
    }

    public function test_gecmis_bir_kez_cekilir_gecici_hatada_yeniden_denenir(): void
    {
        $ons = Varlik::create(['tur' => 'altin', 'kod' => 'ONS', 'ad' => 'Ons', 'birim' => 'ons', 'para_birimi' => 'USD', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'GC=F', 'aktif' => true]);
        $gram = Varlik::create(['tur' => 'altin', 'kod' => 'GRAM', 'ad' => 'Gram', 'birim' => 'gram', 'kaynak' => 'truncgil', 'kaynak_kodu' => 'GRA', 'aktif' => true]);

        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()
            ->push('hata', 500)
            ->push($this->yahoo(3917.5, 3895)),
        ]);
        app(FiyatGuncelleyici::class)->gecmisleriCek();
        $this->assertNull($ons->fresh()->gecmis_tarihi);   // geçici hata: yeniden denenecek
        $this->assertNotNull($gram->fresh()->gecmis_tarihi); // altının geçmişi yok: bir daha denenmez

        app(FiyatGuncelleyici::class)->gecmisleriCek();
        $this->assertNotNull($ons->fresh()->gecmis_tarihi);
        $this->assertSame(2, FiyatGecmisi::where('varlik_id', $ons->id)->count());
    }

    public function test_katalog_esitlemesi_tekrarlanabilir_ve_kullanici_adini_korur(): void
    {
        Katalog::esitle();
        $sayi = Varlik::count();
        Varlik::where('kod', 'GRAM')->update(['ad' => 'Gram altın (kuyumcu)']);
        Katalog::esitle();
        $this->assertSame($sayi, Varlik::count());
        $this->assertSame('Gram altın (kuyumcu)', Varlik::where('kod', 'GRAM')->value('ad'));
    }
}
