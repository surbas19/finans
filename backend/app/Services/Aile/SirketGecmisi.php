<?php

namespace App\Services\Aile;

use App\Enums\KartIslemDurumu;
use App\Models\KartIslemi;
use App\Support\Donem;
use App\Support\Kurus;
use App\Support\Metin;
use Illuminate\Support\Collection;

/**
 * Bir kart satırının şirketinden (aynı açıklama: Metin::sade eşitliği, kategori kuralıyla aynı ölçüt) hanenin bütün
 * kartlarında yapılmış onaylı harcamalar, işlem ayına göre ay ay. O harcamalara bağlı iadeler (`iade_islem_id`)
 * açıklamaları farklı olsa da girer. Tutar etkisi ButceRaporu::giderEtkisi (iade düşer; ödeme / transfer / avans /
 * bonus 0). İşlem tarihi olmayan satır rapor ayına yazılır. Para kuruşla toplanır.
 */
class SirketGecmisi
{
    public function __construct(private ButceRaporu $rapor) {}

    /**
     * @return array<string, mixed>
     */
    public function hesapla(KartIslemi $islem): array
    {
        $haneId = (int) $islem->hane_id;
        $sade = Metin::sade((string) $islem->aciklama);

        $onayli = KartIslemi::where('hane_id', $haneId)->where('durum', KartIslemDurumu::Onayli->value);
        /** @var Collection<int, KartIslemi> $satirlar */
        $satirlar = $sade === ''
            ? (clone $onayli)->whereKey($islem->id)->get()
            : (clone $onayli)->get()->filter(fn (KartIslemi $s): bool => Metin::sade((string) $s->aciklama) === $sade);
        $idler = $satirlar->pluck('id')->all();
        if ($idler !== []) {
            $iadeler = (clone $onayli)->whereIn('iade_islem_id', $idler)->whereNotIn('id', $idler)->get();
            $satirlar = $satirlar->concat($iadeler);
        }

        $ayinda = fn (KartIslemi $s): ?string => $s->islem_tarihi ? Donem::tarihten($s->islem_tarihi) : $s->rapor_donemi;
        $aylar = $satirlar
            ->filter(fn (KartIslemi $s): bool => $ayinda($s) !== null)
            ->groupBy($ayinda)
            ->sortKeysDesc()
            ->map(function (Collection $grup, string $donem): array {
                $sirali = $grup->sortByDesc(fn (KartIslemi $s): string => ($s->islem_tarihi?->toDateString() ?? '').'#'.str_pad((string) $s->id, 10, '0', STR_PAD_LEFT));

                return [
                    'donem' => $donem,
                    'etiket' => Donem::etiket($donem),
                    'kurus' => $grup->sum(fn (KartIslemi $s): int => $this->rapor->giderEtkisi($s)),
                    'adet' => $grup->count(),
                    'islemler' => $sirali->map(fn (KartIslemi $s): array => [
                        'id' => $s->id,
                        'kart_id' => $s->kart_id,
                        'islem_tarihi' => $s->islem_tarihi?->toDateString(),
                        'aciklama' => $s->aciklama,
                        'tur' => $s->tur->value,
                        'tutar' => Kurus::tl(Kurus::kurus($s->tutar)),
                        'etki' => Kurus::tl($this->rapor->giderEtkisi($s)),
                        'taksit_no' => $s->taksit_no,
                        'taksit_sayisi' => $s->taksit_sayisi,
                    ])->values()->all(),
                ];
            })
            ->values();

        $toplam = (int) $aylar->sum('kurus');
        $ilk = $aylar->last()['donem'] ?? null;
        $son = $aylar->first()['donem'] ?? null;
        $buAy = Donem::buAy();
        // Aylık ortalama: ilk harcama ayından bu aya (son harcama ayı daha sonraysa ona) kadar bütün aylar, boş aylar dahil
        $ayAdedi = $ilk !== null ? Donem::fark($ilk, max((string) $son, $buAy)) + 1 : 0;

        return [
            'aciklama' => $islem->aciklama,
            'kategori_id' => $islem->kategori_id,
            'toplam' => Kurus::tl($toplam),
            'adet' => $aylar->sum('adet'),
            'ilk_donem' => $ilk,
            'aylik_ortalama' => $ayAdedi > 0 ? Kurus::tl((int) round($toplam / $ayAdedi)) : null,
            'bu_ay' => Kurus::tl((int) ($aylar->firstWhere('donem', $buAy)['kurus'] ?? 0)),
            'aylar' => $aylar->map(function (array $ay): array {
                $ay['toplam'] = Kurus::tl($ay['kurus']);
                unset($ay['kurus']);

                return $ay;
            })->all(),
        ];
    }
}
