<?php

namespace App\Enums;

enum VarlikTuru: string
{
    case Altin = 'altin';
    case Doviz = 'doviz';
    case Hisse = 'hisse';
    case Fon = 'fon';
    case Kripto = 'kripto';

    public function etiket(): string
    {
        return match ($this) {
            self::Altin => 'Altın / gümüş',
            self::Doviz => 'Döviz',
            self::Hisse => 'Hisse senedi',
            self::Fon => 'Yatırım fonu',
            self::Kripto => 'Kripto',
        };
    }
}
