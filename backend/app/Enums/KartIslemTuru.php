<?php

namespace App\Enums;

/**
 * Kart satırının türü ve gider toplamına etkisi (ButceRaporu bu yönü kullanır):
 * harcama ve faiz / ücret gider (+), iade gideri azaltır (−); kart borcu ödemesi, hesaplar arası transfer ve nakit
 * avans gider değildir (0): ödeme ekstredeki harcamaları ikinci kez saydırmaz.
 */
enum KartIslemTuru: string
{
    case Harcama = 'harcama';
    case Iade = 'iade';
    case Odeme = 'odeme';
    case Transfer = 'transfer';
    case Ucret = 'ucret';
    case NakitAvans = 'nakit_avans';

    public function giderYonu(): int
    {
        return match ($this) {
            self::Harcama, self::Ucret => 1,
            self::Iade => -1,
            self::Odeme, self::Transfer, self::NakitAvans => 0,
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::Harcama => 'Harcama',
            self::Iade => 'İade / iptal',
            self::Odeme => 'Kart borcu ödemesi',
            self::Transfer => 'Transfer',
            self::Ucret => 'Faiz / ücret',
            self::NakitAvans => 'Nakit avans',
        };
    }
}
