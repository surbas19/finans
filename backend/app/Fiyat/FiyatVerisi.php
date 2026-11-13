<?php

namespace App\Fiyat;

use Carbon\CarbonInterface;

/**
 * Bir kaynaktan gelen tek fiyat. `fiyat` değerleme fiyatıdır: alış (bid) varsa o, yoksa son fiyat.
 */
final class FiyatVerisi
{
    public function __construct(
        public readonly float $fiyat,
        public readonly ?float $alis = null,
        public readonly ?float $satis = null,
        public readonly ?float $oncekiKapanis = null,
        public readonly ?float $degisimYuzde = null,
        public readonly ?CarbonInterface $zaman = null,
    ) {}
}
