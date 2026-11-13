<?php

namespace App\Enums;

enum HesapTuru: string
{
    case AraciKurum = 'araci_kurum';
    case Banka = 'banka';
    case Kasa = 'kasa';
    case Ev = 'ev';
    case Kripto = 'kripto';
    case Diger = 'diger';

    public function etiket(): string
    {
        return match ($this) {
            self::AraciKurum => 'Aracı kurum / yatırım hesabı',
            self::Banka => 'Banka hesabı',
            self::Kasa => 'Banka kasası',
            self::Ev => 'Evde',
            self::Kripto => 'Kripto borsası / cüzdan',
            self::Diger => 'Diğer',
        };
    }
}
