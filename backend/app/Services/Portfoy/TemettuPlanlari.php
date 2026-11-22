<?php

namespace App\Services\Portfoy;

use App\Enums\IslemTuru;
use App\Models\Islem;
use App\Models\TemettuPlani;
use App\Models\User;
use App\Support\Kurus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Önceden girilen temettü planı (tek yer): lot, brüt, stopaj ve yatacak net hesabı, yaklaşan / geciken şeridi ve "yattı"
 * (planı gerçek temettü kaydına çevirir). Lot plana yazılmaz: ödeme gününde hesaptaki hisse adedidir (o tarihe kadar
 * yapılmış işlemler; ileri tarihte bugüne kadarkiler). Plan toplamlara, zekâta ve Bütçe'ye karışmaz; yatınca
 * `TemettuKaydi` yazar.
 */
class TemettuPlanlari
{
    /** Şeritte "yaklaşıyor" sayılan gün sayısı */
    public const HATIRLATMA_GUN = 3;

    public function __construct(private TemettuKaydi $temettuKaydi) {}

    /** Hesapta hissenin `tarih` itibarıyla adedi (ileri tarih için bugüne kadarki işlemler) */
    public function lot(int $hesapId, int $varlikId, CarbonInterface $tarih): float
    {
        $sinir = min($tarih->toDateString(), now()->toDateString());
        $p = new Pozisyon($hesapId, $varlikId);
        $islemler = Islem::where('hesap_id', $hesapId)->where('varlik_id', $varlikId)->where('tarih', '<=', $sinir)->get();
        foreach (PortfoyHesabi::sirala($islemler) as $islem) {
            try {
                $p->uygula($islem);
            } catch (YetersizMiktar) {
                // Bozuk eski satış hesaba katılmaz
            }
        }

        return max(0.0, round($p->miktar(), 8));
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(TemettuPlani $plan): array
    {
        $lot = $plan->durum === TemettuPlani::YATTI ? null : $this->lot($plan->hesap_id, $plan->varlik_id, $plan->tarih);
        $brut = $lot !== null ? Kurus::kurus(number_format($lot * $plan->hisse_basi, 2, '.', '')) : null;
        $stopaj = $brut !== null ? (int) round($brut * $plan->stopaj_yuzde / 100) : null;
        $fiyat = $plan->varlik->para_birimi === 'TRY' ? $plan->varlik->fiyat?->fiyat : null;

        return [
            'id' => $plan->id,
            'hesap_id' => $plan->hesap_id,
            'hesap_ad' => $plan->hesap->ad,
            'varlik' => ['id' => $plan->varlik->id, 'kod' => $plan->varlik->kod, 'ad' => $plan->varlik->ad],
            'tarih' => $plan->tarih->toDateString(),
            'gun_kaldi' => (int) now()->startOfDay()->diffInDays($plan->tarih->copy()->startOfDay(), false),
            'durum' => $plan->durum,
            'lot' => $lot,
            'hisse_basi' => $plan->hisse_basi,
            'oran_yuzde' => $fiyat ? round($plan->hisse_basi / $fiyat * 100, 2) : null,
            'brut' => $brut !== null ? Kurus::tl($brut) : null,
            'stopaj_yuzde' => $plan->stopaj_yuzde,
            'stopaj' => $stopaj !== null ? Kurus::tl($stopaj) : null,
            'net' => $brut !== null ? Kurus::tl($brut - $stopaj) : null,
            'not' => $plan->not,
            'yatti_at' => $plan->yatti_at?->toIso8601String(),
        ];
    }

    /**
     * Özet şeridi: bekleyen planlardan ödeme gününe `HATIRLATMA_GUN` gün ve altı kalanlar ile günü geçmiş ("yattı mı?").
     *
     * @return list<array<string, mixed>>
     */
    public function serit(User $user): array
    {
        return TemettuPlani::erisilebilir($user)->with(['hesap', 'varlik.fiyat'])->where('durum', TemettuPlani::BEKLIYOR)
            ->whereDate('tarih', '<=', now()->addDays(self::HATIRLATMA_GUN)->toDateString())
            ->orderBy('tarih')->get()->map(fn (TemettuPlani $p): array => $this->ozet($p))
            ->filter(fn (array $o): bool => ($o['lot'] ?? 0) > 0)->values()->all();
    }

    /**
     * Planı gerçek temettüye çevirir: hesaptaki lot × hisse başı − stopaj (ya da bankanın verdiği net) hisse satırında
     * temettü + hesaba nakit olarak yazılır. Tarih planın günü (ileri tarihliyse bugün).
     *
     * @throws ValidationException
     */
    public function yatti(TemettuPlani $plan, User $user, ?float $net = null): TemettuPlani
    {
        if ($plan->durum !== TemettuPlani::BEKLIYOR) {
            throw ValidationException::withMessages(['durum' => 'Bu temettü zaten kaydedilmiş.']);
        }
        $tarih = $plan->tarih->isFuture() ? now()->startOfDay() : $plan->tarih;
        $lot = $this->lot($plan->hesap_id, $plan->varlik_id, $tarih);
        if ($lot <= 0) {
            throw ValidationException::withMessages(['tarih' => 'Bu tarihte hesapta bu hisse yok; temettü kaydedilemez.']);
        }
        $brut = Kurus::kurus(number_format($lot * $plan->hisse_basi, 2, '.', ''));
        $netKurus = $net !== null ? Kurus::kurus(number_format($net, 2, '.', '')) : $brut - (int) round($brut * $plan->stopaj_yuzde / 100);
        if ($netKurus <= 0) {
            throw ValidationException::withMessages(['net' => 'Net tutar sıfırdan büyük olmalı.']);
        }

        return DB::transaction(function () use ($plan, $user, $tarih, $brut, $netKurus): TemettuPlani {
            $islem = new Islem([
                'user_id' => $user->id, 'hesap_id' => $plan->hesap_id, 'varlik_id' => $plan->varlik_id, 'tur' => IslemTuru::Temettu,
                'miktar' => Kurus::tl($netKurus), 'komisyon' => Kurus::tl(max(0, $brut - $netKurus)), 'tarih' => $tarih->toDateString(), 'not' => $plan->not,
            ]);
            $this->temettuKaydi->kaydet($islem);
            $plan->update(['durum' => TemettuPlani::YATTI, 'islem_id' => $islem->id, 'yatti_at' => now()]);

            return $plan;
        });
    }
}
