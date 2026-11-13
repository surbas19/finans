<?php

namespace App\Support;

/**
 * Karşılaştırma için metin: Türkçe büyük harf, işaretsiz (Ç→C, Ğ→G, İ/I→I, Ö→O, Ş→S, Ü→U), tek boşluk.
 * Ekstre açıklaması ile sağlayıcı / kategori deseni bu biçimde karşılaştırılır.
 */
final class Metin
{
    public static function sade(string $metin): string
    {
        $buyuk = mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $metin), 'UTF-8');
        $isaretsiz = strtr($buyuk, ['Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U', 'Â' => 'A', 'Î' => 'I', 'Û' => 'U']);

        return trim((string) preg_replace('/\s+/u', ' ', $isaretsiz));
    }

    /** Sade metinde, kelime sınırına bakarak (EDAS "KEDASX"in içinde bulunmaz) desen geçiyor mu */
    public static function geciyor(string $sadeMetin, string $sadeDesen): bool
    {
        if ($sadeDesen === '') {
            return false;
        }

        return preg_match('/(^|[^A-Z0-9])'.preg_quote($sadeDesen, '/').'($|[^A-Z0-9])/', $sadeMetin) === 1;
    }
}
