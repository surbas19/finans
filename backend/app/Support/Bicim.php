<?php

namespace App\Support;

class Bicim
{
    /**
     * Türkçe sayı: 1.234,5 (gereksiz sıfırlar atılır, en çok 8 hane).
     */
    public static function sayi(float $deger, int $hane = 8): string
    {
        $metin = number_format($deger, $hane, ',', '.');
        if (str_contains($metin, ',')) {
            $metin = rtrim(rtrim($metin, '0'), ',');
        }

        return $metin;
    }
}
