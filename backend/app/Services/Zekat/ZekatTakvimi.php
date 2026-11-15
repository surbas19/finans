<?php

namespace App\Services\Zekat;

use App\Models\ZekatAyari;
use App\Support\HicriTakvim;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Ayardaki zekât gününün (hicrî ya da miladî ay / gün) takvimdeki sıradaki tarihi. Hicrî günlerde tarih her seferinde
 * hicrî yıl / ay / günden yeniden hesaplanır (yıl 354 gün sabitiyle ilerletilmez).
 */
class ZekatTakvimi
{
    /**
     * `$gun` dahil, o günden sonraki ilk zekât günü.
     *
     * @return array{tarih: Carbon, tahmini: bool, yontem: string}
     */
    public function ilk(ZekatAyari $ayar, CarbonInterface $gun): array
    {
        $gun = Carbon::create($gun->year, $gun->month, $gun->day);
        if ($ayar->zekat_gunu_turu === 'miladi') {
            return ['tarih' => $this->miladi((int) $ayar->miladi_ay, (int) $ayar->miladi_gun, $gun), 'tahmini' => false, 'yontem' => 'miladi'];
        }

        return [
            'tarih' => HicriTakvim::sonraki((int) $ayar->hicri_ay, (int) $ayar->hicri_gun, $gun),
            // Ümmülkura da Diyanet takvimiyle bir gün ayrışabilir; yedek algoritma daha da gevşek
            'tahmini' => true,
            'yontem' => HicriTakvim::yontem(),
        ];
    }

    /**
     * `$gun`'den SONRAKİ ilk zekât günü (bir zekât günü kapanınca bir sonrakine geçmek için).
     *
     * @return array{tarih: Carbon, tahmini: bool, yontem: string}
     */
    public function sonrasi(ZekatAyari $ayar, CarbonInterface $gun): array
    {
        return $this->ilk($ayar, Carbon::create($gun->year, $gun->month, $gun->day)->addDay());
    }

    private function miladi(int $ay, int $gun, Carbon $baslangic): Carbon
    {
        foreach ([$baslangic->year, $baslangic->year + 1] as $yil) {
            $aday = Carbon::create($yil, $ay, 1);
            $aday->day(min($gun, $aday->daysInMonth));
            if ($aday->gte($baslangic)) {
                return $aday;
            }
        }

        return Carbon::create($baslangic->year + 2, $ay, 1);
    }
}
