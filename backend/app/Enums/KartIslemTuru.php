<?php

namespace App\Enums;

/**
 * Kart satırının türü ve gider toplamına etkisi (ButceRaporu bu yönü kullanır):
 * harcama, faiz, ücret / aidat ve vergi (KKDF / BSMV) gider (+), iade gideri azaltır (−); kart borcu ödemesi, hesaplar
 * arası transfer, nakit avans ve bonus / puanla ödeme gider değildir (0): ödeme ekstredeki harcamaları ikinci kez
 * saydırmaz; bonusla ödenen harcama zaten harcama olarak sayılmıştır (borcu düşürür, gideri değil).
 */
enum KartIslemTuru: string
{
    case Harcama = 'harcama';
    case Iade = 'iade';
    case Odeme = 'odeme';
    case Transfer = 'transfer';
    case Ucret = 'ucret';
    case NakitAvans = 'nakit_avans';
    case Faiz = 'faiz';
    case Vergi = 'vergi';
    case Bonus = 'bonus';

    public function giderYonu(): int
    {
        return match ($this) {
            self::Harcama, self::Ucret, self::Faiz, self::Vergi => 1,
            self::Iade => -1,
            self::Odeme, self::Transfer, self::NakitAvans, self::Bonus => 0,
        };
    }

    /** Borcu azaltan (alacak) tür mü: ödeme, iade, bonus */
    public function alacakMi(): bool
    {
        return in_array($this, [self::Odeme, self::Iade, self::Bonus], true);
    }

    public function etiket(): string
    {
        return match ($this) {
            self::Harcama => 'Harcama',
            self::Iade => 'İade / iptal',
            self::Odeme => 'Kart borcu ödemesi',
            self::Transfer => 'Transfer',
            self::Ucret => 'Ücret / aidat',
            self::NakitAvans => 'Nakit avans',
            self::Faiz => 'Faiz',
            self::Vergi => 'Vergi (KKDF / BSMV)',
            self::Bonus => 'Bonus / puanla ödenen',
        };
    }
}
