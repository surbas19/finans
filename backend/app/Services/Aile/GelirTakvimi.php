<?php

namespace App\Services\Aile;

use App\Enums\GelirDuzeni;
use App\Models\Gelir;
use App\Models\GelirKaynagi;
use App\Support\Donem;
use Illuminate\Support\Collection;

/**
 * Düzenli kaynaklardan beklenen gelir. Beklenen kayıt değildir: gerçekleşen toplama katılmaz, ayrı listelenir.
 * Kaynak, başlangıç ayından itibaren sıklığı kadar ayda bir (bitiş ayına kadar) beklenir; o dönemin kaydı
 * girilmişse artık beklenmez.
 */
class GelirTakvimi
{
    public function bekleniyorMu(GelirKaynagi $kaynak, string $donem): bool
    {
        if (! $kaynak->aktif || $kaynak->duzen !== GelirDuzeni::Duzenli || $kaynak->siklik === null) {
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
    public function beklenenler(int $haneId, string $donem, ?int $uyeId = null): Collection
    {
        $kaynaklar = GelirKaynagi::where('hane_id', $haneId)
            ->where('aktif', true)->where('duzen', GelirDuzeni::Duzenli->value)
            ->when($uyeId, fn ($q, $id) => $q->where('uye_id', $id))
            ->orderBy('id')->get()
            ->filter(fn (GelirKaynagi $k): bool => $this->bekleniyorMu($k, $donem));
        $girilen = Gelir::where('donem', $donem)->whereIn('gelir_kaynagi_id', $kaynaklar->modelKeys())
            ->pluck('gelir_kaynagi_id')->flip();

        return $kaynaklar->reject(fn (GelirKaynagi $k): bool => $girilen->has($k->id))
            ->map(fn (GelirKaynagi $k): array => [
                'kaynak' => $k->ozet(),
                'donem' => $donem,
                // Beklenen gün ay sonunu aşmaz (31 → Şubat'ta 28 / 29)
                'tarih' => $k->gun ? Donem::ilkGun($donem)->day(min($k->gun, Donem::sonGun($donem)->day))->toDateString() : null,
            ])->values();
    }
}
