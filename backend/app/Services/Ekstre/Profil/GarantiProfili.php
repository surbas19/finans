<?php

namespace App\Services\Ekstre\Profil;

use App\Support\Kurus;
use Illuminate\Support\Carbon;

/**
 * Garanti BBVA (Bonus). Satır: `22.10.2026 SANLI LAHMACUN \t1.440,00` (tutar sağdaki sütun; ortada bonus olabilir).
 * Taksit: `2.149,60x5=10.748,00 4.Taksit`. Satır araları kategori başlığı ("Kitap Evi & Kırtasiye"). Kesim tarihi
 * yazmaz: ekstre numarasının içinde (`910-20261111…`) ya da "Bir sonraki hesap kesiminiz" − 1 ay. Özet satırı
 * `önceki TL+ harcama TL+ faiz TL- ödeme TL= borç TL asgari TL` bütün toplamları verir.
 */
class GarantiProfili extends EkstreProfili
{
    private const TUTAR = '(\d{1,3}(?:\.\d{3})*,\d{2})';

    public function ad(): string
    {
        return 'garanti';
    }

    public function etiket(): string
    {
        return 'Garanti BBVA';
    }

    public function parmakIzleri(): array
    {
        return ['GARANTI BBVA', 'GARANTI BANKASI', 'GARANTIBBVA'];
    }

    public function alanEtiketleri(): array
    {
        return [
            'donem_borcu' => ['TOPLAM BORCUNUZ'],
            'donem_baslangic' => ['ONCEKI HESAP KESIM TARIHINIZ'],
        ];
    }

    public function tabloSinirliMi(): bool
    {
        return true;
    }

    public function tabloBasi(string $sade): bool
    {
        return str_contains($sade, 'ISLEM TARIHI') && str_contains($sade, 'DONEM ICI ISLEMLER');
    }

    public function tabloSonu(string $sade): bool
    {
        return str_starts_with($sade, 'TOPLAM ') || str_starts_with($sade, 'EKSTRE OZETI');
    }

    public function tutarKonumu(): string
    {
        return 'son';
    }

    public function taksit(string $sade): ?array
    {
        if (preg_match('/'.self::TUTAR.'X(\d{1,2})='.self::TUTAR.'\s+(\d{1,2})\.\s*TAKSIT/', $sade, $e)) {
            return [(int) $e[4], (int) $e[2], Kurus::decimal(Kurus::kurus(str_replace(['.', ','], ['', '.'], $e[3])))];
        }

        return null;
    }

    public function aciklamaTemizle(string $aciklama): string
    {
        return trim((string) preg_replace('/\s*\d[\d.]*,\d{2}\s*x\s*\d{1,2}\s*=\s*\d[\d.]*,\d{2}\s+\d{1,2}\.\s*Taksit.*$/iu', '', $aciklama));
    }

    public function toplamSatiri(string $sade): ?string
    {
        if (preg_match('/^TOPLAM\s+(?:-?'.self::TUTAR.'\s+)?'.self::TUTAR.'$/', $sade, $e)) {
            return self::decimal($e[2]);
        }

        return null;
    }

    public function turetilenAlanlar(array $alanlar, array $sadeSatirlar): array
    {
        $sonuc = [];
        foreach ($sadeSatirlar as $satir) {
            if (! isset($sonuc['kesim_tarihi']) && preg_match('/EKSTRE NO\s*:?\s*\d{2,4}-(\d{4})(\d{2})(\d{2})\d*/', $satir, $e) && checkdate((int) $e[2], (int) $e[3], (int) $e[1])) {
                $sonuc['kesim_tarihi'] = "{$e[1]}-{$e[2]}-{$e[3]}";
            }
            if (! isset($sonuc['kesim_tarihi']) && preg_match('/SONRAKI HESAP KESIMINIZ (\d{2})\.(\d{2})\.(\d{4})/', $satir, $e)) {
                $sonuc['kesim_tarihi'] = Carbon::create((int) $e[3], (int) $e[2], (int) $e[1])->subMonthNoOverflow()->toDateString();
            }
            // Önceki TL+ harcama TL+ faiz TL- ödeme TL= borç TL asgari TL
            $t = self::TUTAR;
            if (preg_match("/^{$t}\s*TL\+\s*{$t}\s*TL\+\s*{$t}\s*TL-\s*{$t}\s*TL=\s*{$t}\s*TL\s+{$t}\s*TL/", $satir, $e)) {
                $sonuc += [
                    'onceki_borc' => self::decimal($e[1]),
                    'harcama_toplami' => self::decimal($e[2]),
                    'faiz_toplami' => self::decimal($e[3]),
                    'odeme_toplami' => self::decimal($e[4]),
                    'donem_borcu' => self::decimal($e[5]),
                    'asgari_odeme' => self::decimal($e[6]),
                ];
            }
        }

        return $sonuc;
    }

    private static function decimal(string $tutar): string
    {
        return Kurus::decimal(Kurus::kurus(str_replace(['.', ','], ['', '.'], $tutar)));
    }
}
