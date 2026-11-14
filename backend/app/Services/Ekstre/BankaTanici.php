<?php

namespace App\Services\Ekstre;

use App\Services\Ekstre\Profil\EkstreProfili;
use App\Services\Ekstre\Profil\EnparaProfili;
use App\Services\Ekstre\Profil\GarantiProfili;
use App\Services\Ekstre\Profil\GenelProfil;
use App\Services\Ekstre\Profil\YapiKrediProfili;
use App\Support\Metin;

/**
 * Ekstrenin bankası: ilk iki sayfanın sade metninde profil parmak izi aranır. Bulunamazsa genel profil (uyarıyla).
 */
class BankaTanici
{
    /**
     * @return list<EkstreProfili>
     */
    public function profiller(): array
    {
        return [new GarantiProfili, new YapiKrediProfili, new EnparaProfili];
    }

    public function profil(string $ad): EkstreProfili
    {
        foreach ($this->profiller() as $profil) {
            if ($profil->ad() === $ad) {
                return $profil;
            }
        }

        return new GenelProfil;
    }

    /**
     * @param  list<list<string>>  $sayfalar  normalize edilmiş
     */
    public function tani(array $sayfalar): EkstreProfili
    {
        $metin = Metin::sade(implode("\n", array_merge(...(array_slice($sayfalar, 0, 2) ?: [[]]))));
        foreach ($this->profiller() as $profil) {
            foreach ($profil->parmakIzleri() as $iz) {
                if (str_contains($metin, $iz)) {
                    return $profil;
                }
            }
        }

        return new GenelProfil;
    }
}
