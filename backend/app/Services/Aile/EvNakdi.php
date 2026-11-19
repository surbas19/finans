<?php

namespace App\Services\Aile;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Models\DuzenliGider;
use App\Models\EvNakdiHareketi;
use App\Models\Hane;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\Pozisyon;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ev nakdi (tek yer). İki kip:
 * - **Hesap kipi** (`haneler.ev_hesap_id` dolu, ör. "Ev" hesabı): bakiye = o portföy hesabının Nakit (TL) tutarı. Ev
 *   harcaması o hesaba Nakit satışı (`bag_turu = harcama`), "para ekle / çıkar" Nakit alış / satışı olarak yazılır;
 *   Portföy ve Bütçe aynı nakdi gösterir. Ayrı kasaya önceden girilmiş kayıtlar yok sayılır (kullanıcı kararı:
 *   hesabın nakdi esas); geçmiş ev harcamaları Bütçe'de gider olarak kalır, hesaptan geriye dönük düşülmez.
 * - **Kasa kipi** (hesap seçilmemiş): bakiye = eklenenler − çıkarılanlar − hesaba yazılmamış "ev" ödeme yerli giderler.
 * Bakiye hiçbir kipte eksiye düşmez (yetmeyen harcama / çıkarma reddedilir).
 */
class EvNakdi
{
    public function nakitVarligi(): ?Varlik
    {
        return Varlik::where('tur', VarlikTuru::Nakit->value)->orderBy('id')->first();
    }

    /** Hesaptaki Nakit (TL) miktarı (tüm işlemler, tarih sırasıyla; bozuk eski satış sayılmaz) */
    public function hesapNakit(Hesap $hesap): float
    {
        $nakit = $this->nakitVarligi();
        if (! $nakit) {
            return 0.0;
        }
        $p = new Pozisyon($hesap->id, $nakit->id);
        foreach (PortfoyHesabi::sirala(Islem::where('hesap_id', $hesap->id)->where('varlik_id', $nakit->id)->get()) as $islem) {
            try {
                $p->uygula($islem);
            } catch (YetersizMiktar) {
                // PortfoyHesabi::hesapla ile aynı: geçersiz eski satış atlanır
            }
        }

        return max(0.0, $p->miktar());
    }

    /** Ev nakdi olarak seçilmiş hesap (hanenin bir kullanıcısına ait değilse yok sayılır) */
    public function evHesabi(int $haneId): ?Hesap
    {
        $id = Hane::whereKey($haneId)->value('ev_hesap_id');

        return $id ? Hesap::whereKey($id)->whereIn('user_id', User::where('hane_id', $haneId)->pluck('id'))->first() : null;
    }

    /**
     * Ev nakdi bakiyesi (kuruş). Kasa kipinde `haricGiderId`: güncellenen giderin eski tutarı düşülmüş sayılmaz.
     */
    public function bakiyeKurus(int $haneId, ?int $haricGiderId = null): int
    {
        $hesap = $this->evHesabi($haneId);
        if ($hesap) {
            return (int) round($this->hesapNakit($hesap) * 100);
        }
        $toplam = fn (string $tur): int => EvNakdiHareketi::where('hane_id', $haneId)->where('tur', $tur)->get()
            ->reduce(fn (int $t, EvNakdiHareketi $h): int => $t + Kurus::kurus($h->tutar), 0);

        return $toplam(EvNakdiHareketi::EKLEME) - $toplam(EvNakdiHareketi::CIKARMA) - $this->kasaHarcamalari($haneId, $haricGiderId)
            ->reduce(fn (int $t, DuzenliGider $g): int => $t + Kurus::kurus($g->tutar), 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(int $haneId): array
    {
        $hesap = $this->evHesabi($haneId);

        return $hesap ? $this->hesapOzeti($hesap) : $this->kasaOzeti($haneId);
    }

    /**
     * Kasa kipindeki ev harcamaları: hesaba yazılmamış (islem_id boş) "ev" giderleri. Hesap kipinde girilip sonra
     * hesap bağı kaldırılan harcama zaten hesaptan düşmüştür, kasadan ikinci kez düşülmez.
     *
     * @return Collection<int, DuzenliGider>
     */
    private function kasaHarcamalari(int $haneId, ?int $haricGiderId = null)
    {
        return DuzenliGider::where('hane_id', $haneId)->where('odeme_yeri', 'ev')->whereNull('islem_id')
            ->when($haricGiderId, fn ($q) => $q->whereKeyNot($haricGiderId))->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function kasaOzeti(int $haneId): array
    {
        $hareketler = EvNakdiHareketi::where('hane_id', $haneId)->get();
        $harcamalar = $this->kasaHarcamalari($haneId);

        $giren = $hareketler->where('tur', EvNakdiHareketi::EKLEME)->reduce(fn (int $t, EvNakdiHareketi $h): int => $t + Kurus::kurus($h->tutar), 0);
        $cikan = $hareketler->where('tur', EvNakdiHareketi::CIKARMA)->reduce(fn (int $t, EvNakdiHareketi $h): int => $t + Kurus::kurus($h->tutar), 0);
        $harcama = $harcamalar->reduce(fn (int $t, DuzenliGider $g): int => $t + Kurus::kurus($g->tutar), 0);

        // Son hareketler: kasaya para ekleme / çıkarma + ev harcamaları, yeniden eskiye (tarih, sonra id)
        $son = $hareketler->map(fn (EvNakdiHareketi $h): array => [
            'tip' => $h->tur, 'id' => $h->id, 'tutar' => Kurus::tl(Kurus::kurus($h->tutar)), 'tarih' => $h->tarih->toDateString(),
            'aciklama' => $h->not, 'silinebilir' => true,
        ])->concat($harcamalar->map(fn (DuzenliGider $g): array => [
            'tip' => 'harcama', 'id' => $g->id, 'tutar' => Kurus::tl(Kurus::kurus($g->tutar)), 'tarih' => $g->tarih->toDateString(),
            'aciklama' => $g->aciklama, 'silinebilir' => false,
        ]))->sortBy([['tarih', 'desc'], ['id', 'desc']])->take(20)->values();

        return [
            'hesap' => null,
            'bakiye' => Kurus::tl($giren - $cikan - $harcama),
            'giren' => Kurus::tl($giren),
            'cikan' => Kurus::tl($cikan),
            'harcama' => Kurus::tl($harcama),
            'hareketler' => $son,
        ];
    }

    /**
     * Hesap kipi: hareketler hesabın Nakit (TL) işlemleridir (alış / elde olan = giren, harcamadan gelen satış = harcama,
     * diğer satış = çıkan). Portföy'de düzenlenir; burada silinmez.
     *
     * @return array<string, mixed>
     */
    private function hesapOzeti(Hesap $hesap): array
    {
        $nakit = $this->nakitVarligi();
        $islemler = $nakit ? Islem::where('hesap_id', $hesap->id)->where('varlik_id', $nakit->id)->get() : collect();
        $tip = fn (Islem $i): string => $i->tur === IslemTuru::Satis ? ($i->bag_turu === 'harcama' ? 'harcama' : 'cikarma') : 'ekleme';
        $kurus = fn (Islem $i): int => (int) round((float) $i->miktar * 100);
        $topla = fn (string $t): int => $islemler->filter(fn (Islem $i): bool => $tip($i) === $t)->reduce(fn (int $s, Islem $i): int => $s + $kurus($i), 0);

        $son = $islemler->sortBy([fn (Islem $a, Islem $b): int => [$b->tarih, $b->id] <=> [$a->tarih, $a->id]])->take(20)
            ->map(fn (Islem $i): array => [
                'tip' => $tip($i), 'id' => $i->id, 'tutar' => Kurus::tl($kurus($i)), 'tarih' => $i->tarih->toDateString(),
                'aciklama' => $i->not !== null ? preg_replace('/^(Nakit harcama|Ev nakdi): /u', '', $i->not) : null, 'silinebilir' => false,
            ])->values();

        return [
            'hesap' => ['id' => $hesap->id, 'ad' => $hesap->ad],
            'bakiye' => round($this->hesapNakit($hesap), 2),
            'giren' => Kurus::tl($topla('ekleme')),
            'cikan' => Kurus::tl($topla('cikarma')),
            'harcama' => Kurus::tl($topla('harcama')),
            'hareketler' => $son,
        ];
    }
}
