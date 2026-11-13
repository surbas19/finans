<?php

namespace Tests\Feature;

use App\Models\FiyatGecmisi;
use App\Models\User;
use App\Models\Varlik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

class VarlikTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    public function test_hisse_kodu_yahoo_dan_dogrulanip_eklenir(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/v8/finance/chart/THYAO.IS*' => Http::response(['chart' => ['result' => [[
                'meta' => ['currency' => 'TRY', 'regularMarketPrice' => 319, 'chartPreviousClose' => 314.75, 'longName' => 'Türk Hava Yollari A.O.', 'regularMarketTime' => now()->getTimestamp()],
            ]]]]),
            'query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => null, 'error' => ['code' => 'Not Found']]], 404),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'hisse', 'kod' => 'thyao'])->assertCreated()
            ->assertJsonPath('varlik.kod', 'THYAO')
            ->assertJsonPath('varlik.gecikmeli', true)
            ->assertJsonPath('varlik.fiyat.fiyat', 319);
        $this->assertDatabaseHas('varliklar', ['kod' => 'THYAO', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'THYAO.IS', 'ekleyen_id' => $user->id]);
        // Yeniden eklemek kopya açmaz
        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'hisse', 'kod' => 'THYAO'])->assertOk();
        $this->assertSame(1, Varlik::where('kod', 'THYAO')->count());

        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'hisse', 'kod' => 'YOKBU'])->assertJsonValidationErrors('kod');
        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'hisse', 'kod' => 'A-B'])->assertJsonValidationErrors('kod');
    }

    public function test_kripto_binance_dan_fon_elle_eklenir(): void
    {
        Http::fake([
            'api.binance.com/api/v3/ticker/24hr?symbol=SOLTRY' => Http::response(['symbol' => 'SOLTRY', 'lastPrice' => '8000.5', 'prevClosePrice' => '7900', 'priceChangePercent' => '1.27', 'closeTime' => now()->getTimestampMs()]),
            'api.binance.com/*' => Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'kripto', 'kod' => 'SOL'])->assertCreated()->assertJsonPath('varlik.fiyat.fiyat', 8000.5);
        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'kripto', 'kod' => 'YOKCOIN'])->assertJsonValidationErrors('kod');
        // Fon: ad zorunlu, fiyat yok (elle girilir)
        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'fon', 'kod' => 'AFT'])->assertJsonValidationErrors('ad');
        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'fon', 'kod' => 'AFT', 'ad' => 'Teknoloji fonu'])->assertCreated()
            ->assertJsonPath('varlik.kaynak', 'elle')->assertJsonPath('varlik.fiyat', null);
        // Altın / döviz kullanıcı tarafından eklenmez
        $this->actingAs($user)->postJson('/api/v1/varliklar', ['tur' => 'altin', 'kod' => 'XAU'])->assertJsonValidationErrors('tur');
    }

    public function test_elle_fiyat_yalnizca_yonetici_degisim_dunku_kapanistan(): void
    {
        $fon = $this->varlik('AFT', 'fon', null);
        FiyatGecmisi::create(['varlik_id' => $fon->id, 'tarih' => now()->subDays(3)->toDateString(), 'kapanis' => 2]);

        $this->actingAs(User::factory()->create())->putJson("/api/v1/varliklar/{$fon->id}/fiyat", ['fiyat' => 2.2])->assertForbidden();
        $this->actingAs(User::factory()->yonetici()->create())->putJson("/api/v1/varliklar/{$fon->id}/fiyat", ['fiyat' => 2.2])->assertOk()
            ->assertJsonPath('varlik.fiyat.elle', true)
            ->assertJsonPath('varlik.fiyat.degisim_yuzde', 10);
        $this->assertDatabaseHas('fiyat_gecmisi', ['varlik_id' => $fon->id, 'tarih' => now()->toDateString()]);

        // Nakit ve bireysel emekliliğin fiyatı girilmez (birimi 1 TL)
        $nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit');
        $this->actingAs(User::factory()->yonetici()->create())->putJson("/api/v1/varliklar/{$nakit->id}/fiyat", ['fiyat' => 2])
            ->assertJsonValidationErrors('fiyat');
        $this->assertDatabaseMissing('fiyatlar', ['varlik_id' => $nakit->id]);
    }

    public function test_katalog_arama_ve_gecmis_araligi(): void
    {
        $user = User::factory()->create();
        $hisse = $this->varlik('THYAO');
        $this->varlik('ASELS');
        foreach ([400, 60, 5] as $gun) {
            FiyatGecmisi::create(['varlik_id' => $hisse->id, 'tarih' => now()->subDays($gun)->toDateString(), 'kapanis' => $gun]);
        }

        $this->actingAs($user)->getJson('/api/v1/varliklar?q=thy')->assertOk()->assertJsonCount(1, 'varliklar')->assertJsonPath('varliklar.0.kod', 'THYAO');
        $this->actingAs($user)->getJson("/api/v1/varliklar/{$hisse->id}/gecmis?aralik=1A")->assertJsonCount(1, 'gecmis');
        $this->actingAs($user)->getJson("/api/v1/varliklar/{$hisse->id}/gecmis?aralik=3A")->assertJsonCount(2, 'gecmis');
        $this->actingAs($user)->getJson("/api/v1/varliklar/{$hisse->id}/gecmis?aralik=T")->assertJsonCount(3, 'gecmis')->assertJsonPath('gecmis.0.kapanis', 400);
    }
}
