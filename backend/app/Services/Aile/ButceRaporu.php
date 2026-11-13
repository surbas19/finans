<?php

namespace App\Services\Aile;

use App\Enums\EkstreDurumu;
use App\Enums\GelirDuzeni;
use App\Enums\GelirTuru;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Ekstre;
use App\Models\Gelir;
use App\Models\HaneUyesi;
use App\Models\HarcamaKategorisi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Support\Donem;
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
     * Gider: onaylı kart satırlarından, rapor ayı `$donem` olanlar. Harcama ve faiz / ücret eklenir, iade düşülür
     * (kategorisi yoksa iade edilen harcamanınki); kart borcu ödemesi, transfer ve nakit avans gider sayılmaz, ayrıca
     * gösterilir. Taksitli alışta aya düşen taksit gidere, toplam borç yalnızca bilgi olarak.
     *
     * @return array<string, mixed>
     */
    public function giderOzeti(int $haneId, string $donem): array
    {
        $islemler = KartIslemi::where('hane_id', $haneId)->where('durum', KartIslemDurumu::Onayli->value)
            ->where('rapor_donemi', $donem)->with(['iadeEdilen:id,kategori_id'])->orderBy('islem_tarihi')->get();
        $kategoriler = HarcamaKategorisi::where('hane_id', $haneId)->get()->keyBy('id');
        $kartlar = Kart::where('hane_id', $haneId)->get()->keyBy('id');

        $kategoriToplam = [];
        $kartToplam = [];
        $haric = [];
        foreach ($islemler as $islem) {
            $etki = $this->giderEtkisi($islem);
            if ($islem->tur->giderYonu() === 0) {
                $haric[$islem->tur->value] = ($haric[$islem->tur->value] ?? 0) + Kurus::kurus($islem->tutar);

                continue;
            }
            $kategori = $islem->kategori_id ?? $islem->iadeEdilen?->kategori_id ?? 0;
            $kategoriToplam[$kategori] = ($kategoriToplam[$kategori] ?? 0) + $etki;
            $kartToplam[$islem->kart_id] = ($kartToplam[$islem->kart_id] ?? 0) + $etki;
        }
        $kartHarcamasi = array_sum($kategoriToplam);

        return [
            'toplam' => Kurus::tl($kartHarcamasi),
            'kart_harcamasi' => Kurus::tl($kartHarcamasi),
            'kategoriler' => collect($kategoriToplam)
                ->map(fn (int $t, int $id): array => [
                    'kategori_id' => $id ?: null,
                    'ad' => $kategoriler[$id]->ad ?? 'Kategorisiz',
                    'renk' => $kategoriler[$id]->renk ?? null,
                    'ikon' => $kategoriler[$id]->ikon ?? null,
                    'toplam' => Kurus::tl($t),
                ])->sortByDesc('toplam')->values(),
            'kartlar' => collect($kartToplam)
                ->map(fn (int $t, int $id): array => [
                    'kart_id' => $id,
                    'ad' => $kartlar[$id]->ad ?? '',
                    'sahip_uye_id' => $kartlar[$id]->sahip_uye_id ?? null,
                    'toplam' => Kurus::tl($t),
                ])->sortByDesc('toplam')->values(),
            // Gidere girmeyen satırlar: kullanıcıya neden sayılmadığı gösterilir
            'haric' => collect($haric)->map(fn (int $t, string $tur): array => [
                'tur' => $tur,
                'etiket' => KartIslemTuru::from($tur)->etiket(),
                'toplam' => Kurus::tl($t),
            ])->values(),
            'taksitler' => $islemler
                ->filter(fn (KartIslemi $i): bool => ($i->taksit_sayisi ?? 0) > 1 && $i->tur === KartIslemTuru::Harcama)
                ->map(fn (KartIslemi $i): array => [
                    'islem_id' => $i->id,
                    'aciklama' => $i->aciklama,
                    'kart_id' => $i->kart_id,
                    'taksit_no' => $i->taksit_no,
                    'taksit_sayisi' => $i->taksit_sayisi,
                    'aya_dusen' => Kurus::tl(Kurus::kurus($i->tutar)),
                    // Ekstre toplamı yazmıyorsa taksit × sayı (yaklaşık; taksitler kuruş farklı olabilir)
                    'toplam_borc' => Kurus::tl($i->taksit_toplam !== null ? Kurus::kurus($i->taksit_toplam) : Kurus::kurus($i->tutar) * (int) $i->taksit_sayisi),
                    'toplam_tahmini' => $i->taksit_toplam === null,
                ])->values(),
        ];
    }

    /** Satırın gider toplamına etkisi (kuruş): harcama / ücret +, iade −, ödeme / transfer / nakit avans 0 */
    public function giderEtkisi(KartIslemi $islem): int
    {
        return $islem->tur->giderYonu() * Kurus::kurus($islem->tutar);
    }

    /**
     * Aylık özet: kişi ve hane geliri, kategorilere göre gider, gelir − gider.
     *
     * @return array<string, mixed>
     */
    public function aylikOzet(int $haneId, string $donem): array
    {
        $gelir = $this->gelirOzeti($haneId, $donem);
        $gider = $this->giderOzeti($haneId, $donem);

        return [
            'donem' => $donem,
            'etiket' => Donem::etiket($donem),
            'gelir' => $gelir,
            'gider' => $gider,
            // Toplamlar kuruşun yüzde biri (x/100): iki haneyle metne çevrilince kuruş tam geri gelir
            'fark' => Kurus::tl(Kurus::kurus(number_format($gelir['toplam'], 2, '.', '')) - Kurus::kurus(number_format($gider['toplam'], 2, '.', ''))),
        ];
    }

    /**
     * Uyarılar: son ödeme tarihi 10 gün içinde (ya da en çok 5 gün geçmiş) olan, ödendi işaretlenmemiş ekstreler.
     *
     * @return array<string, mixed>
     */
    public function uyarilar(int $haneId): array
    {
        $bugun = now()->startOfDay();
        $ekstreler = Ekstre::where('hane_id', $haneId)->where('durum', EkstreDurumu::Onaylandi->value)->where('odendi', false)
            ->whereBetween('son_odeme_tarihi', [$bugun->copy()->subDays(5)->toDateString(), $bugun->copy()->addDays(10)->toDateString()])
            ->with('kart')->orderBy('son_odeme_tarihi')->get();

        return [
            'son_odemeler' => $ekstreler->map(fn (Ekstre $e): array => [
                ...$e->ozet(),
                'kart_ad' => $e->kart->ad,
                'gun' => (int) $bugun->diffInDays($e->son_odeme_tarihi, false),
            ])->values(),
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
