<?php

namespace App\Enums;

enum GelirTuru: string
{
    case Maas = 'maas';
    case Kira = 'kira';
    case Serbest = 'serbest';
    case EkIs = 'ek_is';
    case Prim = 'prim';
    case FaizTemettu = 'faiz_temettu';
    case Emekli = 'emekli';
    case Diger = 'diger';

    public function etiket(): string
    {
        return match ($this) {
            self::Maas => 'Maaş',
            self::Kira => 'Kira geliri',
            self::Serbest => 'Serbest çalışma',
            self::EkIs => 'Ek iş',
            self::Prim => 'Prim / ikramiye',
            self::FaizTemettu => 'Faiz / temettü',
            self::Emekli => 'Emekli maaşı',
            self::Diger => 'Diğer',
        };
    }
}
