<?php

namespace App\Services\Zekat;

use App\Models\ZekatAyari;
use App\Models\ZekatOdemesi;
use App\Models\ZekatYili;
use App\Support\HicriTakvim;
use App\Support\Kurus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Zekât yılı = bir zekât gününden (varsayılan 1 Ramazan) bir sonrakine kadar: "Ramazan'dan Ramazan'a". Sınırlar kayıtlı
 * zekât günleridir; kaydı olmayan yıllar için ayardaki zekât gününden (ZekatTakvimi) hesaplanır (hicrî gün tahminîdir,
 * bu yüzden ayardan hesaplanan bir sınır kayıtlı bir günün ±7 günü içindeyse kayıtlı gün esas alınır).
 * Ödemeler yıla bağlıysa o yılın, bağsızsa tarihlerinin düştüğü yılın verilenidir.
 */
class ZekatDonemleri
{
    private const TOLERANS = 7;

    private const AYLAR = [1 => 'Muharrem', 2 => 'Safer', 3 => 'Rebiülevvel', 4 => 'Rebiülahir', 5 => 'Cemaziyelevvel', 6 => 'Cemaziyelahir', 7 => 'Recep', 8 => 'Şaban', 9 => 'Ramazan', 10 => 'Şevval', 11 => 'Zilkade', 12 => 'Zilhicce'];

    public function __construct(private ZekatTakvimi $takvim) {}

    /**
     * Tarihi kapsayan zekât yılı.
     *
     * @return array{baslangic: Carbon, bitis: Carbon}
     */
    public function donemi(int $haneId, CarbonInterface $tarih): array
    {
        $gun = Carbon::create($tarih->year, $tarih->month, $tarih->day);
        $sinirlar = $this->sinirlar($haneId, $gun);
        $baslangic = $sinirlar->filter(fn (Carbon $s): bool => $s->lte($gun))->last() ?? $sinirlar->first();
        $bitis = $sinirlar->first(fn (Carbon $s): bool => $s->gt($gun)) ?? $baslangic->copy()->addYear();

        return ['baslangic' => $baslangic, 'bitis' => $bitis];
    }

    /** Tarihi kapsayan KESİNLEŞMİŞ zekât yılı kaydı (ödemeyi otomatik bağlamak için) */
    public function kayitBul(int $haneId, CarbonInterface $tarih): ?ZekatYili
    {
        $baslangic = $this->donemi($haneId, $tarih)['baslangic'];

        return ZekatYili::where('hane_id', $haneId)->where('durum', ZekatYili::KESINLESTI)
            ->whereDate('zekat_gunu', $baslangic->toDateString())->first();
    }

    /**
     * En yeniden eskiye zekât yılları: ödemesi ya da kaydı olan her yıl ve içinde bulunulan yıl.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function liste(int $haneId, ?CarbonInterface $bugun = null): Collection
    {
        $bugun = Carbon::create(($bugun ?? now())->year, ($bugun ?? now())->month, ($bugun ?? now())->day);
        $ayar = ZekatAyari::haneIcin($haneId);
        $yillar = ZekatYili::where('hane_id', $haneId)->get()->keyBy(fn (ZekatYili $y): string => $y->zekat_gunu->toDateString());
        $yillarId = $yillar->keyBy('id');
        $odemeler = ZekatOdemesi::where('hane_id', $haneId)->orderByDesc('tarih')->orderByDesc('id')->get();

        /** @var array<string, array{baslangic: Carbon, bitis: Carbon, odemeler: Collection<int, ZekatOdemesi>}> $gruplar */
        $gruplar = [];
        $ekle = function (array $donem) use (&$gruplar): string {
            $anahtar = $donem['baslangic']->toDateString();
            $gruplar[$anahtar] ??= [...$donem, 'odemeler' => collect()];

            return $anahtar;
        };
        $ekle($this->donemi($haneId, $bugun));
        foreach ($yillar as $y) {
            $ekle($this->donemi($haneId, $y->zekat_gunu));
        }
        foreach ($odemeler as $o) {
            $bagli = $o->zekat_yili_id !== null ? $yillarId->get($o->zekat_yili_id) : null;
            $anahtar = $ekle($this->donemi($haneId, $bagli ? $bagli->zekat_gunu : $o->tarih));
            $gruplar[$anahtar]['odemeler']->push($o);
        }
        krsort($gruplar);

        return collect($gruplar)->map(function (array $g) use ($yillar, $bugun, $ayar): array {
            $kayit = $yillar->get($g['baslangic']->toDateString());
            $verilen = $g['odemeler']->reduce(fn (int $t, ZekatOdemesi $o): int => $t + Kurus::kurus($o->tutar), 0);
            $gereken = $kayit !== null ? Kurus::kurus($kayit->zekat) : null;

            return [
                'baslangic' => $g['baslangic']->toDateString(),
                'bitis' => $g['bitis']->toDateString(),
                'etiket' => $this->etiket($ayar, $g['baslangic'], $g['bitis']),
                'hicri' => $ayar->zekat_gunu_turu !== 'miladi',
                'guncel' => $bugun->gte($g['baslangic']) && $bugun->lt($g['bitis']),
                'yil' => $kayit ? ['id' => $kayit->id, 'durum' => $kayit->durum, 'nisab_ustu' => (bool) $kayit->nisab_ustu] : null,
                'gereken' => $gereken !== null ? Kurus::tl($gereken) : null,
                'verilen' => Kurus::tl($verilen),
                'kalan' => $gereken !== null ? Kurus::tl(max(0, $gereken - $verilen)) : null,
                'fazla' => $gereken !== null ? Kurus::tl(max(0, $verilen - $gereken)) : null,
                'odemeler' => $g['odemeler']->map(fn (ZekatOdemesi $o): array => $o->ozet())->values(),
            ];
        })->values();
    }

    /**
     * Tarihin etrafındaki zekât günü sınırları (kayıtlı günler esas; ayardan hesaplananlar tamamlar).
     *
     * @return Collection<int, Carbon>
     */
    private function sinirlar(int $haneId, Carbon $gun): Collection
    {
        $kayitli = ZekatYili::where('hane_id', $haneId)->pluck('zekat_gunu')
            ->map(fn ($t): Carbon => Carbon::create($t->year, $t->month, $t->day));
        $ayar = ZekatAyari::haneIcin($haneId);
        $hesaplanan = collect();
        $t = $this->takvim->ilk($ayar, $gun->copy()->subDays(400))['tarih'];
        $son = $gun->copy()->addDays(400);
        for ($i = 0; $i < 6 && $t->lte($son); $i++) {
            $hesaplanan->push($t);
            $t = $this->takvim->sonrasi($ayar, $t)['tarih'];
        }
        // Hesaplanan sınır kayıtlı bir günün ±7 günü içindeyse kayıtlı gün esas alınır
        $kalan = $hesaplanan->reject(fn (Carbon $h): bool => $kayitli->contains(fn (Carbon $k): bool => abs($h->diffInDays($k, false)) <= self::TOLERANS));

        return $kayitli->merge($kalan)->unique(fn (Carbon $c): string => $c->toDateString())->sort(fn (Carbon $a, Carbon $b): int => $a <=> $b)->values();
    }

    private function etiket(ZekatAyari $ayar, Carbon $baslangic, Carbon $bitis): string
    {
        if ($ayar->zekat_gunu_turu === 'miladi') {
            return $baslangic->format('d.m.Y').' → '.$bitis->format('d.m.Y');
        }
        $ad = self::AYLAR[(int) $ayar->hicri_ay] ?? 'Zekât günü';

        return $ad.' '.HicriTakvim::hicriye($baslangic)[0].' → '.$ad.' '.HicriTakvim::hicriye($bitis)[0].' ('.$baslangic->format('d.m.Y').' – '.$bitis->copy()->subDay()->format('d.m.Y').')';
    }
}
