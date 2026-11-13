<?php

namespace App\Services\Aile;

use App\Models\KategoriKurali;
use App\Support\Metin;
use Illuminate\Support\Collection;

/**
 * Kart satırının açıklamasından kategori önerisi: hanenin kuralları (uzun desen önce). Bulunamazsa null (uydurulmaz).
 * Fatura sağlayıcısıyla eşleşen satır Faturalar kategorisine FaturaEslestirici'de girer.
 */
class KategoriBulucu
{
    /** @var array<int, Collection<int, KategoriKurali>> */
    private array $kurallar = [];

    public function bul(int $haneId, string $aciklama): ?int
    {
        $sade = Metin::sade($aciklama);
        $this->kurallar[$haneId] ??= KategoriKurali::where('hane_id', $haneId)->get()
            ->sortByDesc(fn (KategoriKurali $k): int => mb_strlen($k->desen))->values();

        foreach ($this->kurallar[$haneId] as $kural) {
            if (Metin::geciyor($sade, $kural->desen)) {
                return $kural->kategori_id;
            }
        }

        return null;
    }

    public function unut(int $haneId): void
    {
        unset($this->kurallar[$haneId]);
    }
}
