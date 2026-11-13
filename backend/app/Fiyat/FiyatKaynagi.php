<?php

namespace App\Fiyat;

use App\Models\Varlik;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Bir fiyat kaynağı. Kaynaklar resmî değildir; bozulursa öbürleri sürer (FiyatGuncelleyici). Testlerde Http::fake.
 */
interface FiyatKaynagi
{
    public function ad(): string;

    /**
     * Varlıkların güncel fiyatları; kaynakta bulunamayan varlık dizide yer almaz.
     *
     * @param  Collection<int, Varlik>  $varliklar
     * @return array<int, FiyatVerisi> varlik_id => fiyat
     */
    public function guncel(Collection $varliklar): array;

    /**
     * Günlük kapanışlar; kaynak geçmiş vermiyorsa boş dizi.
     *
     * @return array<string, float> Y-m-d => kapanış
     */
    public function gecmis(Varlik $varlik, CarbonInterface $baslangic): array;
}
