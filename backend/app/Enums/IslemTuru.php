<?php

namespace App\Enums;

enum IslemTuru: string
{
    case Alis = 'alis';
    case Satis = 'satis';
    /** Elde olan (başlangıç bakiyesi); fiyatı bilinmeyebilir */
    case Mevcut = 'mevcut';
    /** Bireysel emeklilikte o tarihteki toplam tutar (+ kâr yüzdesi); pozisyonu baştan kurar */
    case Deger = 'deger';
    /**
     * Hisse temettüsü: `miktar` hesaba geçen net TL, `komisyon` stopaj TL (brüt = net + stopaj). Adet ve maliyeti
     * değiştirmez; aynı hesaba Nakit alışıyla eşleşik yazılır (`aktarim_kodu`, `bag_turu = temettu`).
     */
    case Temettu = 'temettu';
    /** Bedelsiz sermaye artırımı: `miktar` oran yüzdesi; adet × (1 + oran / 100), toplam maliyet aynı kalır */
    case Bedelsiz = 'bedelsiz';

    public function etiket(): string
    {
        return match ($this) {
            self::Alis => 'Alış',
            self::Satis => 'Satış',
            self::Mevcut => 'Elde olan',
            self::Deger => 'Güncel tutar',
            self::Temettu => 'Temettü',
            self::Bedelsiz => 'Bedelsiz',
        };
    }

    /** Yalnızca hissede girilen, elde hisse olmasını gerektiren türler */
    public function hisseOlayi(): bool
    {
        return $this === self::Temettu || $this === self::Bedelsiz;
    }
}
