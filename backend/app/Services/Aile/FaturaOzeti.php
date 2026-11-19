<?php

namespace App\Services\Aile;

use App\Enums\FaturaDurumu;
use App\Models\Fatura;
use App\Models\FaturaSaglayicisi;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Support\Collection;

/**
 * Faturalar ekranının özeti ve sağlayıcı geçmişi. Para kuruşla toplanır.
 *
 * - Ay özeti (hizmet ayına göre): toplam, ödendi / bekliyor / gecikti (tutar + adet), önceki ayın toplamı, dönemi
 *   bilinmeyen fatura sayısı ve "gelmedi": aktif sağlayıcının son 3 ayda faturası var ama bu ayda yok (son faturanın
 *   dönemi ve tutarıyla). Beklenen = gelen + gelmeyen.
 * - Sağlayıcı geçmişi: adet, aylık ortalama (fatura başına), en yüksek, son 12 ay toplamı, son 12 ayın serisi
 *   (faturası olmayan ay null; uydurulmaz), bütün faturalar yeniden eskiye (dönemi bilinmeyenler en sonda).
 */
class FaturaOzeti
{
    /** "Gelmedi" sayılması için son faturanın en çok kaç ay önce olabileceği */
    private const GELMEDI_PENCERE = 3;

    /**
     * @return array<string, mixed>
     */
    public function ay(int $haneId, string $donem): array
    {
        /** @var Collection<int, Fatura> $faturalar */
        $faturalar = Fatura::where('hane_id', $haneId)->where('hizmet_donemi', $donem)->get();
        $kume = ['odendi' => [0, 0], 'bekliyor' => [0, 0], 'gecikti' => [0, 0]];
        foreach ($faturalar as $f) {
            $anahtar = $f->durum === FaturaDurumu::Odendi ? 'odendi' : ($f->gecikmisMi() ? 'gecikti' : 'bekliyor');
            $kume[$anahtar][0] += Kurus::kurus($f->tutar);
            $kume[$anahtar][1]++;
        }

        $onceki = Donem::ekle($donem, -1);
        $oncekiKurus = Fatura::where('hane_id', $haneId)->where('hizmet_donemi', $onceki)->pluck('tutar')
            ->reduce(fn (int $t, $tutar): int => $t + Kurus::kurus($tutar), 0);

        $gelenler = $faturalar->pluck('saglayici_id')->all();
        $son = Fatura::where('hane_id', $haneId)
            ->whereBetween('hizmet_donemi', [Donem::ekle($donem, -self::GELMEDI_PENCERE), $onceki])
            ->whereNotIn('saglayici_id', $gelenler)
            ->whereHas('saglayici', fn ($q) => $q->where('aktif', true))
            ->with('saglayici:id,ad')
            ->orderByDesc('hizmet_donemi')->get()
            ->unique('saglayici_id');

        $gelmeyenler = $son->map(fn (Fatura $f): array => [
            'saglayici_id' => $f->saglayici_id,
            'saglayici_ad' => $f->saglayici->ad,
            'fatura_turu_id' => $f->fatura_turu_id,
            'son_donem' => $f->hizmet_donemi,
            'son_donem_etiketi' => Donem::etiket((string) $f->hizmet_donemi),
            'son_tutar' => Kurus::tl(Kurus::kurus($f->tutar)),
        ])->sortBy('saglayici_ad')->values();

        $toplam = $kume['odendi'][0] + $kume['bekliyor'][0] + $kume['gecikti'][0];

        return [
            'donem' => $donem,
            'toplam' => Kurus::tl($toplam),
            'adet' => $faturalar->count(),
            'beklenen' => $faturalar->count() + $gelmeyenler->count(),
            ...collect($kume)->map(fn (array $k): array => ['tutar' => Kurus::tl($k[0]), 'adet' => $k[1]])->all(),
            'onceki_donem' => $onceki,
            'onceki_toplam' => Kurus::tl($oncekiKurus),
            'gelmeyenler' => $gelmeyenler,
            'bilinmeyen_adet' => Fatura::where('hane_id', $haneId)->whereNull('hizmet_donemi')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saglayici(FaturaSaglayicisi $saglayici): array
    {
        /** @var Collection<int, Fatura> $faturalar */
        $faturalar = Fatura::where('saglayici_id', $saglayici->id)
            ->with('kartIslemi:id,kart_id,islem_tarihi,aciklama,tutar')
            ->orderByRaw('hizmet_donemi is null')->orderByDesc('hizmet_donemi')->orderByDesc('id')->get();

        $kuruslar = $faturalar->map(fn (Fatura $f): int => Kurus::kurus($f->tutar));
        $enYuksek = $faturalar->sortByDesc(fn (Fatura $f): int => Kurus::kurus($f->tutar))->first();

        $buAy = Donem::buAy();
        $ilk = Donem::ekle($buAy, -11);
        $aylik = $faturalar->filter(fn (Fatura $f): bool => $f->hizmet_donemi !== null)->keyBy('hizmet_donemi');
        $seri = [];
        $son12 = 0;
        for ($d = $ilk; $d <= $buAy; $d = Donem::ekle($d, 1)) {
            $f = $aylik->get($d);
            $seri[] = ['donem' => $d, 'tutar' => $f ? Kurus::tl(Kurus::kurus($f->tutar)) : null];
            $son12 += $f ? Kurus::kurus($f->tutar) : 0;
        }

        return [
            'saglayici' => ['id' => $saglayici->id, 'ad' => $saglayici->ad, 'fatura_turu_id' => $saglayici->fatura_turu_id],
            'adet' => $faturalar->count(),
            'ortalama' => $faturalar->isEmpty() ? null : Kurus::tl(intdiv($kuruslar->sum() + intdiv($faturalar->count(), 2), $faturalar->count())),
            'en_yuksek' => $enYuksek ? [
                'tutar' => Kurus::tl(Kurus::kurus($enYuksek->tutar)),
                'donem' => $enYuksek->hizmet_donemi,
                'etiket' => $enYuksek->hizmet_donemi ? Donem::etiket($enYuksek->hizmet_donemi) : null,
            ] : null,
            'son_12_ay' => Kurus::tl($son12),
            'seri' => $seri,
            'faturalar' => $faturalar->map(fn (Fatura $f): array => self::satir($f, $saglayici->ad))->values(),
        ];
    }

    /**
     * Listelerdeki fatura satırı: özet + sağlayıcı adı + bağlı kart satırı
     *
     * @return array<string, mixed>
     */
    public static function satir(Fatura $f, ?string $saglayiciAd = null): array
    {
        return [
            ...$f->ozet(),
            'saglayici_ad' => $saglayiciAd ?? $f->saglayici->ad,
            'kart_islemi' => $f->kartIslemi ? [
                'id' => $f->kartIslemi->id,
                'kart_id' => $f->kartIslemi->kart_id,
                'islem_tarihi' => $f->kartIslemi->islem_tarihi?->toDateString(),
                'aciklama' => $f->kartIslemi->aciklama,
                'tutar' => Kurus::tl(Kurus::kurus($f->kartIslemi->tutar)),
            ] : null,
        ];
    }
}
