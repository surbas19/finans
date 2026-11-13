<?php

namespace Tests;

use App\Models\Fiyat;
use App\Models\Varlik;

trait VarlikYardimcisi
{
    protected function varlik(string $kod, string $tur = 'hisse', ?float $fiyat = 100, string $paraBirimi = 'TRY', string $kaynak = 'elle', ?float $degisim = null): Varlik
    {
        $varlik = Varlik::create([
            'tur' => $tur,
            'kod' => $kod,
            'ad' => $kod.' adı',
            'birim' => 'adet',
            'para_birimi' => $paraBirimi,
            'kaynak' => $kaynak,
            'kaynak_kodu' => $kaynak === 'elle' ? null : $kod,
            'aktif' => true,
            'gecmis_tarihi' => now()->toDateString(),
        ]);
        if ($fiyat !== null) {
            Fiyat::create([
                'varlik_id' => $varlik->id,
                'fiyat' => $fiyat,
                'degisim_yuzde' => $degisim,
                'kaynak' => $kaynak,
                'zaman' => now(),
            ]);
        }

        return $varlik;
    }
}
