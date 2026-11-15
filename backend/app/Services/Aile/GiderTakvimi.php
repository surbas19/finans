<?php

namespace App\Services\Aile;

use App\Models\DuzenliGider;
use App\Models\GiderKaynagi;
use App\Support\Donem;
use Illuminate\Support\Collection;

/**
 * Düzenli kaynaklardan beklenen gider (GelirTakvimi ile aynı mantık). Beklenen kayıt değildir, toplama katılmaz;
 * o dönemin kaydı girilmişse artık beklenmez.
 */
class GiderTakvimi
{
    public function bekleniyorMu(GiderKaynagi $kaynak, string $donem): bool
    {
        if (! $kaynak->aktif) {
            return false;
        }
        $baslangic = Donem::tarihten($kaynak->baslangic ?? $kaynak->created_at);
        $fark = Donem::fark($baslangic, $donem);
        if ($fark < 0 || $fark % $kaynak->siklik->ay() !== 0) {
            return false;
        }

        return $kaynak->bitis === null || $donem <= Donem::tarihten($kaynak->bitis);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function beklenenler(int $haneId, string $donem): Collection
    {
        $kaynaklar = GiderKaynagi::where('hane_id', $haneId)->where('aktif', true)->orderBy('id')->get()
            ->filter(fn (GiderKaynagi $k): bool => $this->bekleniyorMu($k, $donem));
        $girilen = DuzenliGider::where('donem', $donem)->whereIn('gider_kaynagi_id', $kaynaklar->modelKeys())
            ->pluck('gider_kaynagi_id')->flip();

        return $kaynaklar->reject(fn (GiderKaynagi $k): bool => $girilen->has($k->id))
            ->map(fn (GiderKaynagi $k): array => [
                'kaynak' => $k->ozet(),
                'donem' => $donem,
                'tarih' => $k->gun ? Donem::ilkGun($donem)->day(min($k->gun, Donem::sonGun($donem)->day))->toDateString() : null,
            ])->values();
    }
}
