<?php

namespace App\Services\Ekstre\Profil;

/**
 * Banka tanınamayınca: yalnızca genel kurallar (bütün satırlar aday, soldaki tutar asıl).
 */
class GenelProfil extends EkstreProfili
{
    public function ad(): string
    {
        return 'genel';
    }

    public function etiket(): string
    {
        return 'Tanınmayan banka';
    }
}
