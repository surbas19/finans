<?php

namespace App\Fiyat\Kaynaklar;

use App\Enums\VarlikTuru;
use App\Fiyat\FiyatKaynagi;
use App\Fiyat\FiyatVerisi;
use App\Models\Varlik;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Altın ve döviz: finans.truncgil.com (ücretsiz, resmî değil). Tek istekte bütün liste gelir.
 * Anahtarlar: USD, EUR… ve GRA (gram), HAS, CEYREKALTIN, YARIMALTIN, TAMALTIN, CUMHURIYETALTINI, ATAALTIN,
 * 14AYARALTIN, 18AYARALTIN, YIA (22 ayar), GUMUS. "Buying" = alış (kuyumcunun / bankanın aldığı), "Change" = % değişim.
 * Geçmiş vermez; dövizin geçmişi Yahoo'dan (USDTRY=X) alınır, altının grafiği eklendiği günden başlar.
 */
class TruncgilKaynagi implements FiyatKaynagi
{
    public const ADRES = 'https://finans.truncgil.com/v4/today.json';

    public function __construct(private YahooKaynagi $yahoo) {}

    public function ad(): string
    {
        return 'truncgil';
    }

    public function guncel(Collection $varliklar): array
    {
        $cevap = Http::timeout(10)->connectTimeout(5)->acceptJson()
            ->withUserAgent('Mozilla/5.0 (finans)')->get(self::ADRES);
        if (! $cevap->successful() || ! is_array($cevap->json())) {
            throw new RuntimeException('Truncgil cevap vermedi (HTTP '.$cevap->status().').');
        }
        $veri = $cevap->json();
        $zaman = isset($veri['Update_Date']) ? Carbon::parse($veri['Update_Date']) : now();

        $sonuc = [];
        foreach ($varliklar as $varlik) {
            $kalem = $veri[$varlik->kaynak_kodu] ?? null;
            if (! is_array($kalem)) {
                continue;
            }
            $alis = self::sayi($kalem['Buying'] ?? null);
            $satis = self::sayi($kalem['Selling'] ?? null);
            $fiyat = $alis ?: $satis;
            if (! $fiyat) {
                continue; // ör. ONS 0 dönüyor
            }
            $sonuc[$varlik->id] = new FiyatVerisi(
                fiyat: $fiyat,
                alis: $alis ?: null,
                satis: $satis ?: null,
                degisimYuzde: self::sayi($kalem['Change'] ?? null),
                zaman: $zaman,
            );
        }

        return $sonuc;
    }

    public function gecmis(Varlik $varlik, CarbonInterface $baslangic): array
    {
        if ($varlik->tur !== VarlikTuru::Doviz) {
            return [];
        }

        return $this->yahoo->gecmisSembol($varlik->kod.'TRY=X', $baslangic);
    }

    /**
     * Sayı ya da "6.310,01" / "6310.01" biçimli metin.
     */
    public static function sayi(mixed $deger): ?float
    {
        if (is_int($deger) || is_float($deger)) {
            return (float) $deger;
        }
        if (! is_string($deger) || trim($deger) === '') {
            return null;
        }
        $metin = str_replace(['%', ' '], '', $deger);
        if (str_contains($metin, ',')) {
            $metin = str_replace(['.', ','], ['', '.'], $metin);
        }

        return is_numeric($metin) ? (float) $metin : null;
    }
}
