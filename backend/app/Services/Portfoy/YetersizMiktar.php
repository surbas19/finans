<?php

namespace App\Services\Portfoy;

use App\Models\Islem;
use RuntimeException;

/**
 * Satış, o tarihte hesapta olandan fazla.
 */
final class YetersizMiktar extends RuntimeException
{
    public function __construct(public readonly Islem $islem, public readonly float $eldeki)
    {
        parent::__construct('Yetersiz miktar');
    }
}
