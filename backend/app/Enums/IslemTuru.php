<?php

namespace App\Enums;

enum IslemTuru: string
{
    case Alis = 'alis';
    case Satis = 'satis';
    /** Elde olan (başlangıç bakiyesi); fiyatı bilinmeyebilir */
    case Mevcut = 'mevcut';

    public function etiket(): string
    {
        return match ($this) {
            self::Alis => 'Alış',
            self::Satis => 'Satış',
            self::Mevcut => 'Elde olan',
        };
    }
}
