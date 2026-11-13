<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Aile modülünde para: veritabanında `decimal(15,2)`, hesapta tam sayı kuruş (kayan nokta yok).
 * `kurus("1234.5") = 123450`, `tl(123450) = 1234.5` (yalnızca çıktıda, JSON için).
 */
final class Kurus
{
    /**
     * Decimal metin ya da sayı → kuruş. İkiden çok ondalık hane yuvarlanmaz, reddedilir (doğrulama önce yakalar).
     */
    public static function kurus(string|int|float|null $tutar): int
    {
        if ($tutar === null || $tutar === '') {
            return 0;
        }
        if (is_int($tutar)) {
            return $tutar * 100;
        }
        // float yalnızca doğrulanmış istek girdisinden gelir (ör. 1234.5): metne çevrilip öyle ayrıştırılır
        $metin = is_float($tutar) ? number_format($tutar, 2, '.', '') : trim($tutar);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2})\d*)?$/', $metin, $e)) {
            throw new InvalidArgumentException('Tutar biçimi geçersiz.');
        }
        if (isset($e[3]) && strlen(rtrim(substr($metin, strpos($metin, '.') + 1), '0')) > 2) {
            throw new InvalidArgumentException('Tutar en çok 2 ondalık hane olabilir.');
        }
        $deger = (int) $e[2] * 100 + (int) str_pad($e[3] ?? '0', 2, '0');

        return ($e[1] ?? '') === '-' ? -$deger : $deger;
    }

    /** Kuruş → veritabanına yazılacak decimal metin ("1234.50") */
    public static function decimal(int $kurus): string
    {
        $isaret = $kurus < 0 ? '-' : '';
        $mutlak = abs($kurus);

        return $isaret.intdiv($mutlak, 100).'.'.str_pad((string) ($mutlak % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Kuruş → JSON çıktısı (TL); yalnızca gösterim için, üzerinde hesap yapılmaz */
    public static function tl(int $kurus): float
    {
        return $kurus / 100;
    }
}
