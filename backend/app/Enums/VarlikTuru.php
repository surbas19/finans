<?php

namespace App\Enums;

enum VarlikTuru: string
{
    case Altin = 'altin';
    case Doviz = 'doviz';
    case Hisse = 'hisse';
    case Fon = 'fon';
    case Kripto = 'kripto';
    /** TL nakit: birimi 1 TL, fiyatı çekilmez */
    case Nakit = 'nakit';
    /** Bireysel emeklilik: birimi 1 TL; tutar ve kâr yüzdesi elle girilir (işlem türü `deger`) */
    case Bes = 'bes';

    public function etiket(): string
    {
        return match ($this) {
            self::Altin => 'Altın / gümüş',
            self::Doviz => 'Döviz',
            self::Hisse => 'Hisse senedi',
            self::Fon => 'Yatırım fonu',
            self::Kripto => 'Kripto',
            self::Nakit => 'Nakit',
            self::Bes => 'Bireysel emeklilik',
        };
    }

    /**
     * Fiyatı kaynaktan gelmez; birimi her zaman 1 TL (miktar = TL tutarı).
     */
    public function sabitFiyatli(): bool
    {
        return $this === self::Nakit || $this === self::Bes;
    }
}
