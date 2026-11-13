<?php

namespace Tests\Feature;

use App\Models\Hesap;
use App\Models\User;
use App\Models\Varlik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Toplu eklemede katalogda olmayan kodlar: önce Borsa İstanbul (Yahoo), sonra kripto (Binance). Gerçek kaynağa istek
 * gitmez (Http::fake).
 */
class TopluVarlikTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private function yahooHisse(float $fiyat, string $ad): array
    {
        return ['chart' => ['result' => [[
            'meta' => ['currency' => 'TRY', 'regularMarketPrice' => $fiyat, 'chartPreviousClose' => $fiyat - 1, 'longName' => $ad, 'regularMarketTime' => now()->getTimestamp()],
        ]]]];
    }

    private function sahteKaynaklar(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/v8/finance/chart/THYAO.IS*' => Http::response($this->yahooHisse(319, 'Türk Hava Yollari A.O.')),
            'query1.finance.yahoo.com/v8/finance/chart/ASELS.IS*' => Http::response($this->yahooHisse(150, 'Aselsan Elektronik Sanayi ve Ticaret A.S.')),
            'query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => null, 'error' => ['code' => 'Not Found']]], 404),
            'api.binance.com/api/v3/ticker/24hr?symbol=SOLTRY' => Http::response(['symbol' => 'SOLTRY', 'lastPrice' => '8000.5', 'prevClosePrice' => '7900', 'priceChangePercent' => '1.27', 'closeTime' => now()->getTimestampMs()]),
            'api.binance.com/*' => Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400),
        ]);
    }

    public function test_once_borsa_sonra_kripto_bulunamayan_ayri_doner(): void
    {
        $this->sahteKaynaklar();
        $user = User::factory()->create();

        $cevap = $this->actingAs($user)->postJson('/api/v1/varliklar/toplu', ['kodlar' => ['thyao', 'ASELS', 'SOL', 'YOKBU', 'THYAO']])
            ->assertOk()
            ->assertJsonPath('bulunamayan', ['YOKBU'])
            ->assertJsonPath('ulasilamayan', []);
        $varliklar = collect($cevap->json('varliklar'))->keyBy('kod');
        $this->assertEqualsCanonicalizing(['THYAO', 'ASELS', 'SOL'], $varliklar->keys()->all());
        $this->assertSame('hisse', $varliklar['THYAO']['tur']);
        $this->assertSame(319, $varliklar['THYAO']['fiyat']['fiyat']);
        $this->assertTrue($varliklar['ASELS']['gecikmeli']);
        $this->assertSame('kripto', $varliklar['SOL']['tur']);
        $this->assertSame(8000.5, $varliklar['SOL']['fiyat']['fiyat']);

        $this->assertDatabaseHas('varliklar', ['tur' => 'hisse', 'kod' => 'THYAO', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'THYAO.IS', 'ekleyen_id' => $user->id]);
        $this->assertDatabaseHas('varliklar', ['tur' => 'kripto', 'kod' => 'SOL', 'kaynak' => 'binance', 'kaynak_kodu' => 'SOLTRY', 'ekleyen_id' => $user->id]);
        $this->assertDatabaseMissing('varliklar', ['kod' => 'YOKBU']);
        $this->assertSame(1, Varlik::where('kod', 'THYAO')->count());
    }

    public function test_katalogda_olan_icin_kaynaga_gidilmez_pasif_etkinlesir(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $hisse = $this->varlik('THYAO', 'hisse', 300);
        $kripto = $this->varlik('SOL', 'kripto', 8000);
        $kripto->forceFill(['aktif' => false])->save();
        // Aynı kod hem fonda hem hissede varsa hisse döner
        $this->varlik('THYAO', 'fon', 1);

        $cevap = $this->actingAs($user)->postJson('/api/v1/varliklar/toplu', ['kodlar' => ['THYAO', 'SOL']])->assertOk();

        Http::assertNothingSent();
        $this->assertEqualsCanonicalizing([$hisse->id, $kripto->id], collect($cevap->json('varliklar'))->pluck('id')->all());
        $this->assertTrue($kripto->fresh()->aktif);
    }

    public function test_kaynaga_ulasilamazsa_bulunamadi_denmez(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::failedConnection(),
            'api.binance.com/api/v3/ticker/24hr?symbol=SOLTRY' => Http::response(['symbol' => 'SOLTRY', 'lastPrice' => '8000.5', 'closeTime' => now()->getTimestampMs()]),
            'api.binance.com/api/v3/ticker/24hr?symbol=PEPETRY' => Http::response('bakımda', 503),
            'api.binance.com/*' => Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400),
        ]);

        // Borsaya ulaşılamadı: kriptoda bulunan eklenir, kalanlar "ulaşılamadı" (kod borsada olabilir)
        $this->actingAs(User::factory()->create())->postJson('/api/v1/varliklar/toplu', ['kodlar' => ['SOL', 'ASELS', 'PEPE']])
            ->assertOk()
            ->assertJsonPath('varliklar.0.kod', 'SOL')
            ->assertJsonPath('bulunamayan', [])
            ->assertJsonPath('ulasilamayan', ['ASELS', 'PEPE']);
    }

    public function test_sinirlar_bicim_ve_giris(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Hesap::factory()->for($user)->create();

        $this->postJson('/api/v1/varliklar/toplu', ['kodlar' => ['THYAO']])->assertUnauthorized();
        $this->actingAs($user)->postJson('/api/v1/varliklar/toplu', ['kodlar' => []])->assertJsonValidationErrors('kodlar');
        $this->actingAs($user)->postJson('/api/v1/varliklar/toplu', ['kodlar' => array_map(fn (int $i): string => 'KOD'.$i, range(1, 51))])
            ->assertJsonValidationErrors('kodlar');
        $this->actingAs($user)->postJson('/api/v1/varliklar/toplu', ['kodlar' => ['THYAO', 'A-B', 'Ş']])
            ->assertJsonValidationErrors(['kodlar.1', 'kodlar.2'])->assertJsonMissingValidationErrors('kodlar.0');
        Http::assertNothingSent();
    }

    public function test_yalnizca_katalog_doner_kullanici_verisi_donmez(): void
    {
        $this->sahteKaynaklar();
        $baskasi = User::factory()->create();
        Hesap::factory()->for($baskasi)->create(['ad' => 'Başkasının kasası']);

        $cevap = $this->actingAs(User::factory()->create())->postJson('/api/v1/varliklar/toplu', ['kodlar' => ['THYAO']])->assertOk();

        $this->assertSame(['varliklar', 'bulunamayan', 'ulasilamayan'], array_keys($cevap->json()));
        $this->assertStringNotContainsString('Başkasının kasası', $cevap->getContent());
    }
}
