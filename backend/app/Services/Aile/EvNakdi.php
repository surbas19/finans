<?php

namespace App\Services\Aile;

use App\Models\DuzenliGider;
use App\Models\EvNakdiHareketi;
use App\Support\Kurus;

/**
 * Ev nakdi kasası (tek yer): bakiye = eklenenler − çıkarılanlar − ödeme yeri "ev" olan giderler. Banka yalnız etiket,
 * ödeme yeri boş olan eski giderler kasaya dokunmaz. Bakiye eksiye düşmez: yetmeyen harcama, çıkarma ve harcanmış paranın
 * silinmesi reddedilir (`bakiyeKurus`).
 */
class EvNakdi
{
    /**
     * Ev nakdi bakiyesi (kuruş). `haricGiderId`: güncellenen giderin eski tutarı bakiyeden düşülmüş sayılmaz.
     */
    public function bakiyeKurus(int $haneId, ?int $haricGiderId = null): int
    {
        $toplam = fn (string $tur): int => EvNakdiHareketi::where('hane_id', $haneId)->where('tur', $tur)->get()
            ->reduce(fn (int $t, EvNakdiHareketi $h): int => $t + Kurus::kurus($h->tutar), 0);
        $harcama = DuzenliGider::where('hane_id', $haneId)->where('odeme_yeri', 'ev')
            ->when($haricGiderId, fn ($q) => $q->whereKeyNot($haricGiderId))->get()
            ->reduce(fn (int $t, DuzenliGider $g): int => $t + Kurus::kurus($g->tutar), 0);

        return $toplam(EvNakdiHareketi::EKLEME) - $toplam(EvNakdiHareketi::CIKARMA) - $harcama;
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(int $haneId): array
    {
        $hareketler = EvNakdiHareketi::where('hane_id', $haneId)->get();
        $harcamalar = DuzenliGider::where('hane_id', $haneId)->where('odeme_yeri', 'ev')->get();

        $giren = $hareketler->where('tur', EvNakdiHareketi::EKLEME)->reduce(fn (int $t, EvNakdiHareketi $h): int => $t + Kurus::kurus($h->tutar), 0);
        $cikan = $hareketler->where('tur', EvNakdiHareketi::CIKARMA)->reduce(fn (int $t, EvNakdiHareketi $h): int => $t + Kurus::kurus($h->tutar), 0);
        $harcama = $harcamalar->reduce(fn (int $t, DuzenliGider $g): int => $t + Kurus::kurus($g->tutar), 0);

        // Son hareketler: kasaya para ekleme / çıkarma + ev harcamaları, yeniden eskiye (tarih, sonra id)
        $son = $hareketler->map(fn (EvNakdiHareketi $h): array => [
            'tip' => $h->tur, 'id' => $h->id, 'tutar' => Kurus::tl(Kurus::kurus($h->tutar)), 'tarih' => $h->tarih->toDateString(), 'aciklama' => $h->not,
        ])->concat($harcamalar->map(fn (DuzenliGider $g): array => [
            'tip' => 'harcama', 'id' => $g->id, 'tutar' => Kurus::tl(Kurus::kurus($g->tutar)), 'tarih' => $g->tarih->toDateString(), 'aciklama' => $g->aciklama,
        ]))->sortBy([['tarih', 'desc'], ['id', 'desc']])->take(20)->values();

        return [
            'bakiye' => Kurus::tl($giren - $cikan - $harcama),
            'giren' => Kurus::tl($giren),
            'cikan' => Kurus::tl($cikan),
            'harcama' => Kurus::tl($harcama),
            'hareketler' => $son,
        ];
    }
}
