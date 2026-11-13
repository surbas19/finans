<?php

namespace App\Fiyat\Kaynaklar;

use App\Fiyat\FiyatKaynagi;
use App\Fiyat\FiyatVerisi;
use App\Models\Varlik;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kripto: Binance açık API, TRY pariteleri (BTCTRY), anahtarsız. Değişim 24 saatliktir.
 */
class BinanceKaynagi implements FiyatKaynagi
{
    public const ADRES = 'https://api.binance.com/api/v3/';

    public function ad(): string
    {
        return 'binance';
    }

    public function guncel(Collection $varliklar): array
    {
        $semboller = $varliklar->pluck('kaynak_kodu')->filter()->unique()->values();
        if ($semboller->isEmpty()) {
            return [];
        }
        $cevap = Http::timeout(10)->connectTimeout(5)->acceptJson()
            ->get(self::ADRES.'ticker/24hr', ['symbols' => json_encode($semboller->all())]);
        if (! $cevap->successful() || ! is_array($cevap->json())) {
            throw new RuntimeException('Binance cevap vermedi (HTTP '.$cevap->status().').');
        }
        $tablo = collect($cevap->json())->keyBy('symbol');

        $sonuc = [];
        foreach ($varliklar as $varlik) {
            $kalem = $tablo->get($varlik->kaynak_kodu);
            $veri = is_array($kalem) ? self::coz($kalem) : null;
            if ($veri) {
                $sonuc[$varlik->id] = $veri;
            }
        }

        return $sonuc;
    }

    public function gecmis(Varlik $varlik, CarbonInterface $baslangic): array
    {
        $cevap = Http::timeout(15)->connectTimeout(5)->acceptJson()->get(self::ADRES.'klines', [
            'symbol' => $varlik->kaynak_kodu,
            'interval' => '1d',
            'startTime' => $baslangic->getTimestamp() * 1000,
            'limit' => 1000,
        ]);
        if ($cevap->clientError()) {
            return [];
        }
        if (! $cevap->successful() || ! is_array($cevap->json())) {
            throw new RuntimeException('Binance geçmiş cevap vermedi (HTTP '.$cevap->status().').');
        }
        $gecmis = [];
        foreach ($cevap->json() as $mum) {
            if (is_array($mum) && isset($mum[0], $mum[4]) && is_numeric($mum[4])) {
                $gecmis[Carbon::createFromTimestampMs((int) $mum[0])->toDateString()] = (float) $mum[4];
            }
        }

        return $gecmis;
    }

    /**
     * @return array{fiyat: FiyatVerisi}|null
     */
    public function dogrula(string $sembol): ?array
    {
        $cevap = Http::timeout(10)->connectTimeout(5)->acceptJson()->get(self::ADRES.'ticker/24hr', ['symbol' => $sembol]);
        $veri = $cevap->successful() && is_array($cevap->json()) ? self::coz($cevap->json()) : null;

        return $veri ? ['fiyat' => $veri] : null;
    }

    /**
     * Birden çok TRY paritesini aynı anda doğrular. Her sembol ayrı istek: çoklu `symbols=` sorgusu tek geçersiz
     * sembolde bütünüyle 400 döner. 400 (geçersiz sembol) "bulunamadı"; bağlantı hatası, 429, 5xx "ulaşılamadı".
     *
     * @param  list<string>  $semboller
     * @return array{bulunan: array<string, array{fiyat: FiyatVerisi}>, bulunamayan: list<string>, ulasilamayan: list<string>}
     */
    public function dogrulaHepsi(array $semboller): array
    {
        $cevaplar = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $sembol) => $pool->as($sembol)
                ->timeout(10)->connectTimeout(5)->acceptJson()
                ->get(self::ADRES.'ticker/24hr', ['symbol' => $sembol]),
            $semboller,
        ), 10);

        $sonuc = ['bulunan' => [], 'bulunamayan' => [], 'ulasilamayan' => []];
        foreach ($semboller as $sembol) {
            $cevap = $cevaplar[$sembol] ?? null;
            $veri = $cevap instanceof Response && $cevap->successful() && is_array($cevap->json()) ? self::coz($cevap->json()) : null;
            if ($veri) {
                $sonuc['bulunan'][$sembol] = ['fiyat' => $veri];
            } elseif ($cevap instanceof Response && ($cevap->badRequest() || $cevap->successful())) {
                $sonuc['bulunamayan'][] = $sembol;
            } else {
                $sonuc['ulasilamayan'][] = $sembol;
            }
        }

        return $sonuc;
    }

    /**
     * @param  array<string, mixed>  $kalem
     */
    private static function coz(array $kalem): ?FiyatVerisi
    {
        $fiyat = $kalem['lastPrice'] ?? null;
        if (! is_numeric($fiyat) || (float) $fiyat <= 0) {
            return null;
        }
        $onceki = $kalem['prevClosePrice'] ?? null;

        return new FiyatVerisi(
            fiyat: (float) $fiyat,
            alis: is_numeric($kalem['bidPrice'] ?? null) ? (float) $kalem['bidPrice'] : null,
            satis: is_numeric($kalem['askPrice'] ?? null) ? (float) $kalem['askPrice'] : null,
            oncekiKapanis: is_numeric($onceki) && (float) $onceki > 0 ? (float) $onceki : null,
            degisimYuzde: is_numeric($kalem['priceChangePercent'] ?? null) ? (float) $kalem['priceChangePercent'] : null,
            zaman: isset($kalem['closeTime']) ? Carbon::createFromTimestampMs((int) $kalem['closeTime']) : now(),
        );
    }
}
