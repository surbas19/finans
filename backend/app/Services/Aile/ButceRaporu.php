<?php

namespace App\Services\Aile;

use App\Enums\GelirDuzeni;
use App\Enums\GelirTuru;
use App\Models\Gelir;
use App\Models\HaneUyesi;
use App\Support\Kurus;
use Illuminate\Support\Collection;

/**
 * Aile raporlarının TEK yeri: hangi kayıt hangi toplama, hangi aya girer. Tutarlar kuruşla toplanır.
 *
 * Gelir: dönemi o ay olan gerçekleşen gelirler (`tl_tutar`); kişi ve tür bazında; düzenli ile düzensiz / tek
 * seferlik ayrı. Beklenen (henüz alınmamış) gelirler ayrı listelenir, toplama katılmaz.
 */
class ButceRaporu
{
    public function __construct(private GelirTakvimi $takvim) {}

    /**
     * @return array<string, mixed>
     */
    public function gelirOzeti(int $haneId, string $donem): array
    {
        $gelirler = Gelir::where('hane_id', $haneId)->where('donem', $donem)->get();
        $uyeler = HaneUyesi::where('hane_id', $haneId)->orderBy('sira')->orderBy('id')->get();
        $beklenenler = $this->takvim->beklenenler($haneId, $donem);

        $kisiler = $uyeler
            ->map(function (HaneUyesi $uye) use ($gelirler, $beklenenler): array {
                $kendi = $gelirler->where('uye_id', $uye->id);

                return [
                    'uye' => $uye->ozet(),
                    ...$this->toplamlar($kendi),
                    'turler' => $kendi->groupBy(fn (Gelir $g): string => $g->tur->value)
                        ->map(fn (Collection $grup, string $tur): array => [
                            'tur' => $tur,
                            'etiket' => GelirTuru::from($tur)->etiket(),
                            'toplam' => Kurus::tl($this->topla($grup)),
                        ])->sortByDesc('toplam')->values(),
                    'beklenen_sayisi' => $beklenenler->where('kaynak.uye_id', $uye->id)->count(),
                ];
            })
            // Bu ay geliri ya da beklenen geliri olmayan pasif üye gösterilmez
            ->filter(fn (array $k): bool => $k['uye']['aktif'] || $k['toplam'] > 0 || $k['beklenen_sayisi'] > 0)
            ->values();

        return [
            'donem' => $donem,
            ...$this->toplamlar($gelirler),
            'kisiler' => $kisiler,
            'beklenenler' => $beklenenler,
        ];
    }

    /**
     * @param  Collection<int, Gelir>  $gelirler
     * @return array{toplam: float, duzenli: float, duzensiz: float, tek_seferlik: float}
     */
    private function toplamlar(Collection $gelirler): array
    {
        return [
            'toplam' => Kurus::tl($this->topla($gelirler)),
            'duzenli' => Kurus::tl($this->topla($gelirler->where('duzen', GelirDuzeni::Duzenli))),
            'duzensiz' => Kurus::tl($this->topla($gelirler->where('duzen', GelirDuzeni::Duzensiz))),
            'tek_seferlik' => Kurus::tl($this->topla($gelirler->where('duzen', GelirDuzeni::TekSeferlik))),
        ];
    }

    /**
     * @param  Collection<int, Gelir>  $gelirler
     */
    private function topla(Collection $gelirler): int
    {
        return $gelirler->reduce(fn (int $t, Gelir $g): int => $t + Kurus::kurus($g->tl_tutar), 0);
    }
}
