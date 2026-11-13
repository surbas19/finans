<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Ay dönemi "YYYY-AA" (ör. 2026-01): gelirin ait olduğu ay, faturanın hizmet ayı, kart harcamasının rapor ayı.
 */
final class Donem
{
    public const DESEN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    private const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public static function gecerli(?string $donem): bool
    {
        return $donem !== null && preg_match(self::DESEN, $donem) === 1;
    }

    public static function tarihten(CarbonInterface $tarih): string
    {
        return $tarih->format('Y-m');
    }

    public static function buAy(): string
    {
        return now()->format('Y-m');
    }

    /** `$donem`'e `$ay` ay ekler (eksi olabilir) */
    public static function ekle(string $donem, int $ay): string
    {
        return Carbon::createFromFormat('!Y-m', $donem)->addMonthsNoOverflow($ay)->format('Y-m');
    }

    /** b - a, ay cinsinden */
    public static function fark(string $a, string $b): int
    {
        [$ya, $aa] = array_map('intval', explode('-', $a));
        [$yb, $ab] = array_map('intval', explode('-', $b));

        return ($yb - $ya) * 12 + ($ab - $aa);
    }

    public static function ilkGun(string $donem): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $donem)->startOfMonth();
    }

    public static function sonGun(string $donem): Carbon
    {
        return self::ilkGun($donem)->endOfMonth()->startOfDay();
    }

    /** "Ocak 2026" */
    public static function etiket(string $donem): string
    {
        [$y, $a] = array_map('intval', explode('-', $donem));

        return self::AYLAR[$a - 1].' '.$y;
    }

    /** Türkçe ay adı → ay numarası (büyük / küçük harf ve işaret farkı gözetmez; ŞUBAT = SUBAT) */
    public static function ayNumarasi(string $ad): ?int
    {
        $sade = Metin::sade($ad);
        foreach (self::AYLAR as $i => $ay) {
            if (Metin::sade($ay) === $sade) {
                return $i + 1;
            }
        }

        return null;
    }
}
