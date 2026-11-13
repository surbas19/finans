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
 * BIST hisseleri (THYAO.IS), ons altın (GC=F) ve döviz geçmişi (USDTRY=X): Yahoo Finance chart ucu.
 * Resmî değil, ~15 dk gecikmeli, kişisel kullanım. Her sembol ayrı istek (aynı anda, Http::pool).
 */
class YahooKaynagi implements FiyatKaynagi
{
    public const ADRES = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function ad(): string
    {
        return 'yahoo';
    }

    public function guncel(Collection $varliklar): array
    {
        $liste = $varliklar->values();
        $cevaplar = Http::pool(fn (Pool $pool): array => $liste->map(
            fn (Varlik $varlik) => $pool->as((string) $varlik->id)
                ->timeout(10)->connectTimeout(5)->withUserAgent('Mozilla/5.0 (finans)')
                ->get(self::ADRES.rawurlencode((string) $varlik->kaynak_kodu), ['range' => '1d', 'interval' => '1d'])
        )->all());

        $sonuc = [];
        foreach ($liste as $varlik) {
            $cevap = $cevaplar[(string) $varlik->id] ?? null;
            $veri = $cevap instanceof Response ? self::fiyatCoz($cevap) : null;
            if ($veri) {
                $sonuc[$varlik->id] = $veri;
            }
        }

        return $sonuc;
    }

    public function gecmis(Varlik $varlik, CarbonInterface $baslangic): array
    {
        return $this->gecmisSembol((string) $varlik->kaynak_kodu, $baslangic);
    }

    /**
     * @return array<string, float>
     */
    public function gecmisSembol(string $sembol, CarbonInterface $baslangic): array
    {
        $cevap = Http::timeout(15)->connectTimeout(5)->withUserAgent('Mozilla/5.0 (finans)')
            ->get(self::ADRES.rawurlencode($sembol), [
                'period1' => $baslangic->getTimestamp(),
                'period2' => now()->getTimestamp(),
                'interval' => '1d',
            ]);
        if ($cevap->notFound()) {
            return [];
        }
        $sonuc = $cevap->json('chart.result.0');
        if (! $cevap->successful() || ! is_array($sonuc)) {
            throw new RuntimeException('Yahoo geçmiş cevap vermedi (HTTP '.$cevap->status().').');
        }
        $zamanlar = $sonuc['timestamp'] ?? [];
        $kapanislar = $sonuc['indicators']['quote'][0]['close'] ?? [];
        $dilim = $sonuc['meta']['exchangeTimezoneName'] ?? 'Europe/Istanbul';

        $gecmis = [];
        foreach ($zamanlar as $i => $zaman) {
            $kapanis = $kapanislar[$i] ?? null;
            if (is_numeric($kapanis) && $kapanis > 0) {
                $gecmis[Carbon::createFromTimestamp((int) $zaman, $dilim)->toDateString()] = (float) $kapanis;
            }
        }

        return $gecmis;
    }

    /**
     * Kataloğa eklenecek sembolü doğrular: varsa adı ve fiyatı.
     *
     * @return array{ad: string, para_birimi: string, fiyat: FiyatVerisi}|null
     */
    public function dogrula(string $sembol): ?array
    {
        $cevap = Http::timeout(10)->connectTimeout(5)->withUserAgent('Mozilla/5.0 (finans)')
            ->get(self::ADRES.rawurlencode($sembol), ['range' => '1d', 'interval' => '1d']);

        return self::ozetCoz($cevap, $sembol);
    }

    /**
     * Birden çok sembolü aynı anda doğrular (toplu eklemedeki yeni kodlar). Yalnızca 404 ya da fiyatsız cevap
     * "bulunamadı" sayılır; bağlantı hatası, 429, 5xx "ulaşılamadı" (kod var olabilir).
     *
     * @param  list<string>  $semboller
     * @return array{bulunan: array<string, array{ad: string, para_birimi: string, fiyat: FiyatVerisi}>, bulunamayan: list<string>, ulasilamayan: list<string>}
     */
    public function dogrulaHepsi(array $semboller): array
    {
        $cevaplar = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $sembol) => $pool->as($sembol)
                ->timeout(10)->connectTimeout(5)->withUserAgent('Mozilla/5.0 (finans)')
                ->get(self::ADRES.rawurlencode($sembol), ['range' => '1d', 'interval' => '1d']),
            $semboller,
        ), 10);

        $sonuc = ['bulunan' => [], 'bulunamayan' => [], 'ulasilamayan' => []];
        foreach ($semboller as $sembol) {
            $cevap = $cevaplar[$sembol] ?? null;
            $ozet = $cevap instanceof Response ? self::ozetCoz($cevap, $sembol) : null;
            if ($ozet) {
                $sonuc['bulunan'][$sembol] = $ozet;
            } elseif ($cevap instanceof Response && ($cevap->notFound() || $cevap->successful())) {
                $sonuc['bulunamayan'][] = $sembol;
            } else {
                $sonuc['ulasilamayan'][] = $sembol;
            }
        }

        return $sonuc;
    }

    /**
     * @return array{ad: string, para_birimi: string, fiyat: FiyatVerisi}|null
     */
    private static function ozetCoz(Response $cevap, string $sembol): ?array
    {
        $fiyat = self::fiyatCoz($cevap);
        if (! $fiyat) {
            return null;
        }
        $meta = $cevap->json('chart.result.0.meta');

        return [
            'ad' => trim((string) ($meta['longName'] ?? $meta['shortName'] ?? $sembol)),
            'para_birimi' => (string) ($meta['currency'] ?? 'TRY'),
            'fiyat' => $fiyat,
        ];
    }

    private static function fiyatCoz(Response $cevap): ?FiyatVerisi
    {
        $meta = $cevap->successful() ? $cevap->json('chart.result.0.meta') : null;
        $fiyat = is_array($meta) ? ($meta['regularMarketPrice'] ?? null) : null;
        if (! is_numeric($fiyat) || $fiyat <= 0) {
            return null;
        }
        $onceki = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? null;
        $degisim = $meta['regularMarketChangePercent'] ?? null;
        if (! is_numeric($degisim) && is_numeric($onceki) && $onceki > 0) {
            $degisim = ($fiyat - $onceki) / $onceki * 100;
        }

        return new FiyatVerisi(
            fiyat: (float) $fiyat,
            oncekiKapanis: is_numeric($onceki) && $onceki > 0 ? (float) $onceki : null,
            degisimYuzde: is_numeric($degisim) ? round((float) $degisim, 4) : null,
            zaman: isset($meta['regularMarketTime']) ? Carbon::createFromTimestamp((int) $meta['regularMarketTime']) : now(),
        );
    }
}
