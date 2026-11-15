<?php

namespace App\Services\Aile;

use App\Enums\EkstreDurumu;
use App\Enums\FaturaDurumu;
use App\Enums\GelirDuzeni;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\DuzenliGider;
use App\Models\Ekstre;
use App\Models\Fatura;
use App\Models\FaturaTuru;
use App\Models\Gelir;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\HarcamaKategorisi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Models\Tasarruf;
use App\Services\Zekat\ZekatHatirlatici;
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
    /** Kategori dağılımında ekstre borcu ile satır toplamı arasındaki fark (devreden borç, ödeme, bonus…) */
    private const EKSTREDEKI_DIGER = -1;

    public function __construct(private GelirTakvimi $takvim, private GiderTakvimi $giderTakvimi, private ZekatHatirlatici $zekat, private GunTakvimi $gunTakvimi) {}

    /**
     * @return array<string, mixed>
     */
    public function gelirOzeti(int $haneId, string $donem): array
    {
        $gelirler = Gelir::where('hane_id', $haneId)->where('donem', $donem)->get();
        $uyeler = HaneUyesi::where('hane_id', $haneId)->orderBy('sira')->orderBy('id')->get();
        $beklenenler = $this->takvim->beklenenler($haneId, $donem);
        $turAdlari = GelirTuru::where('hane_id', $haneId)->pluck('ad', 'id');

        $kisiler = $uyeler
            ->map(function (HaneUyesi $uye) use ($gelirler, $beklenenler, $turAdlari): array {
                $kendi = $gelirler->where('uye_id', $uye->id);

                return [
                    'uye' => $uye->ozet(),
                    ...$this->toplamlar($kendi),
                    'turler' => $kendi->groupBy('gelir_turu_id')
                        ->map(fn (Collection $grup, int $tur): array => [
                            'gelir_turu_id' => $tur,
                            'etiket' => $turAdlari[$tur] ?? '',
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
     * Gider: kart tarafı kesim ayı bu ay olan onaylı ekstrelerin **dönem borcu** (devreden borç, ödeme, bonus… dahil,
     * ayrılmaz) + henüz ekstreye girmemiş elle kart satırları; kategori dağılımı satırlardan, aradaki fark "ekstredeki
     * diğer" dilimi. Ayrıca kartsız ödenen faturalar ve düzenli giderler.
     *
     * @return array<string, mixed>
     */
    public function giderOzeti(int $haneId, string $donem): array
    {
        $kategoriler = HarcamaKategorisi::where('hane_id', $haneId)->get()->keyBy('id');
        $kartlar = Kart::where('hane_id', $haneId)->get()->keyBy('id');

        // Kart gideri = kesim ayı bu ay olan onaylı ekstrelerin dönem borcu (devreden borç, ödeme, bonus… dahil).
        $ekstreler = Ekstre::where('hane_id', $haneId)->where('durum', EkstreDurumu::Onaylandi->value)
            ->whereBetween('kesim_tarihi', [Donem::ilkGun($donem)->toDateString(), Donem::sonGun($donem)->toDateString()])
            ->orderBy('kesim_tarihi')->get();
        $ekstreSatirlari = KartIslemi::whereIn('ekstre_id', $ekstreler->modelKeys())->where('durum', KartIslemDurumu::Onayli->value)
            ->with(['iadeEdilen:id,kategori_id'])->orderBy('islem_tarihi')->get();

        $kategoriToplam = [];
        $kartToplam = [];
        $kartEkstre = [];
        $haric = [];
        $kategoriyeYaz = function (KartIslemi $islem, int $etki) use (&$kategoriToplam): void {
            $kategori = $islem->kategori_id ?? $islem->iadeEdilen?->kategori_id ?? 0;
            $kategoriToplam[$kategori] = ($kategoriToplam[$kategori] ?? 0) + $etki;
        };
        foreach ($ekstreler as $ekstre) {
            $satirlar = $ekstreSatirlari->where('ekstre_id', $ekstre->id);
            $satirToplam = 0;
            foreach ($satirlar as $islem) {
                if ($islem->tur->giderYonu() === 0) {
                    $haric[$islem->tur->value] = ($haric[$islem->tur->value] ?? 0) + Kurus::kurus($islem->tutar);

                    continue;
                }
                $etki = $this->giderEtkisi($islem);
                $satirToplam += $etki;
                $kategoriyeYaz($islem, $etki);
            }
            // Dönem borcu okunamadıysa satırlardan; okunduysa tamamı gider, satırlarla farkı "ekstredeki diğer" (devreden borç…)
            $borc = $ekstre->donem_borcu !== null ? Kurus::kurus($ekstre->donem_borcu) : $satirToplam;
            if ($borc !== $satirToplam) {
                $kategoriToplam[self::EKSTREDEKI_DIGER] = ($kategoriToplam[self::EKSTREDEKI_DIGER] ?? 0) + $borc - $satirToplam;
            }
            $kartToplam[$ekstre->kart_id] = ($kartToplam[$ekstre->kart_id] ?? 0) + $borc;
            $kartEkstre[$ekstre->kart_id] = $ekstre;
        }

        // Henüz ekstreye girmemiş elle kart satırları ay giderine eklenir (ilk sonraki ekstre 35 günden yakınsa ekstrededir)
        $onayliEkstreler = Ekstre::where('hane_id', $haneId)->where('durum', EkstreDurumu::Onaylandi->value)->get(['kart_id', 'kesim_tarihi'])->groupBy('kart_id');
        $elle = KartIslemi::where('hane_id', $haneId)->where('durum', KartIslemDurumu::Onayli->value)->whereNull('ekstre_id')
            ->where('rapor_donemi', $donem)->with(['iadeEdilen:id,kategori_id'])->get()
            ->filter(function (KartIslemi $islem) use ($onayliEkstreler): bool {
                if ($islem->tur->giderYonu() === 0 || $islem->islem_tarihi === null) {
                    return false;
                }
                $sonraki = ($onayliEkstreler[$islem->kart_id] ?? collect())
                    ->filter(fn (Ekstre $e): bool => $e->kesim_tarihi->gte($islem->islem_tarihi))->min('kesim_tarihi');

                return $sonraki === null || $islem->islem_tarihi->diffInDays($sonraki) > 35;
            });
        foreach ($elle as $islem) {
            $etki = $this->giderEtkisi($islem);
            $kategoriyeYaz($islem, $etki);
            $kartToplam[$islem->kart_id] = ($kartToplam[$islem->kart_id] ?? 0) + $etki;
        }
        $kartHarcamasi = array_sum($kartToplam);
        $islemler = $ekstreSatirlari->concat($elle);

        // Kartla ödenmemiş (kart satırına bağlı olmayan) ödenmiş faturalar: ödendiği ayın gideri, Faturalar kategorisine.
        // Kart satırına bağlı fatura zaten kart harcamasındadır, ikinci kez sayılmaz.
        $baglantisiz = Fatura::where('hane_id', $haneId)->where('durum', FaturaDurumu::Odendi->value)->whereNull('kart_islemi_id')
            ->whereBetween('odeme_tarihi', [Donem::ilkGun($donem)->toDateString(), Donem::sonGun($donem)->toDateString()])->get();
        $baglantisizToplam = $baglantisiz->reduce(fn (int $t, Fatura $f): int => $t + Kurus::kurus($f->tutar), 0);
        if ($baglantisizToplam > 0) {
            $faturaKategorisi = $kategoriler->firstWhere('fatura', true)?->id ?? 0;
            $kategoriToplam[$faturaKategorisi] = ($kategoriToplam[$faturaKategorisi] ?? 0) + $baglantisizToplam;
        }

        // Düzenli / kartsız giderler: dönemi bu ay olan kayıtlar (kategorisi yoksa "Kategorisiz")
        $duzenli = DuzenliGider::where('hane_id', $haneId)->where('donem', $donem)->orderBy('tarih')->orderBy('id')->get();
        $duzenliToplam = $duzenli->reduce(fn (int $t, DuzenliGider $g): int => $t + Kurus::kurus($g->tutar), 0);
        foreach ($duzenli as $g) {
            $kategoriToplam[$g->kategori_id ?? 0] = ($kategoriToplam[$g->kategori_id ?? 0] ?? 0) + Kurus::kurus($g->tutar);
        }

        return [
            'toplam' => Kurus::tl($kartHarcamasi + $baglantisizToplam + $duzenliToplam),
            'kart_harcamasi' => Kurus::tl($kartHarcamasi),
            'kartsiz_faturalar' => Kurus::tl($baglantisizToplam),
            'duzenli' => [
                'toplam' => Kurus::tl($duzenliToplam),
                'kalemler' => $duzenli->map(fn (DuzenliGider $g): array => $g->ozet())->values(),
            ],
            'beklenenler' => $this->giderTakvimi->beklenenler($haneId, $donem),
            'kategoriler' => collect($kategoriToplam)
                ->map(fn (int $t, int $id): array => [
                    'kategori_id' => $id > 0 ? $id : null,
                    'ad' => $id === self::EKSTREDEKI_DIGER ? 'Ekstredeki diğer (devreden borç, ödeme, bonus…)' : ($kategoriler[$id]->ad ?? 'Kategorisiz'),
                    'renk' => $kategoriler[$id]->renk ?? null,
                    'ikon' => $kategoriler[$id]->ikon ?? null,
                    'toplam' => Kurus::tl($t),
                    'diger' => $id === self::EKSTREDEKI_DIGER,
                ])->sortByDesc('toplam')->values(),
            'kartlar' => collect($kartToplam)
                ->map(fn (int $t, int $id): array => [
                    'kart_id' => $id,
                    'ad' => $kartlar[$id]->ad ?? '',
                    'sahip_uye_id' => $kartlar[$id]->sahip_uye_id ?? null,
                    'toplam' => Kurus::tl($t),
                    'ekstre' => isset($kartEkstre[$id]) ? [
                        'id' => $kartEkstre[$id]->id,
                        'donem_borcu' => $kartEkstre[$id]->donem_borcu !== null ? Kurus::tl(Kurus::kurus($kartEkstre[$id]->donem_borcu)) : null,
                        'son_odeme_tarihi' => $kartEkstre[$id]->son_odeme_tarihi?->toDateString(),
                    ] : null,
                ])->sortByDesc('toplam')->values(),
            // Ödeme / bonus / nakit avans: ekstre borcunun içinde; ayrıca gidere eklenmez
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
            'sonraki_taksitler' => $this->sonrakiTaksitler($islemler, $kartlar),
        ];
    }

    /**
     * Rapor ayındaki taksitli harcamaların bu aydan SONRA kalan taksitleri, kart başına: kalan tutar ekstrenin "kalan"
     * alanından, yoksa aya düşen × kalan taksit sayısı (yaklaşık).
     *
     * @param  Collection<int, KartIslemi>  $islemler
     * @param  Collection<int, Kart>  $kartlar
     * @return Collection<int, array<string, mixed>>
     */
    private function sonrakiTaksitler(Collection $islemler, Collection $kartlar): Collection
    {
        $kalemler = $islemler
            ->filter(fn (KartIslemi $i): bool => ($i->taksit_sayisi ?? 0) > 1 && $i->tur === KartIslemTuru::Harcama && ($i->taksit_no ?? 0) >= 1 && $i->taksit_no < $i->taksit_sayisi)
            ->map(function (KartIslemi $i): array {
                $kalanAdet = (int) $i->taksit_sayisi - (int) $i->taksit_no;
                $tahmini = $i->taksit_kalan === null;

                return [
                    'islem_id' => $i->id,
                    'kart_id' => $i->kart_id,
                    'aciklama' => $i->aciklama,
                    'kalan_adet' => $kalanAdet,
                    'aya_dusen' => Kurus::kurus($i->tutar),
                    'kalan_kurus' => $tahmini ? Kurus::kurus($i->tutar) * $kalanAdet : Kurus::kurus($i->taksit_kalan),
                    'tahmini' => $tahmini,
                ];
            });

        return $kalemler->groupBy('kart_id')->map(fn ($grup, $kartId): array => [
            'kart_id' => (int) $kartId,
            'ad' => $kartlar[$kartId]->ad ?? '',
            'toplam' => Kurus::tl($grup->sum('kalan_kurus')),
            'tahmini' => $grup->contains('tahmini', true),
            'kalem_sayisi' => $grup->count(),
            'kalemler' => $grup->map(fn (array $k): array => [
                'islem_id' => $k['islem_id'],
                'aciklama' => $k['aciklama'],
                'kalan_adet' => $k['kalan_adet'],
                'aya_dusen' => Kurus::tl($k['aya_dusen']),
                'kalan_toplam' => Kurus::tl($k['kalan_kurus']),
                'tahmini' => $k['tahmini'],
            ])->values(),
        ])->sortByDesc('toplam')->values();
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
        $tasarruf = $this->tasarrufOzeti($haneId, $donem);
        $kurus = fn (float $tl): int => Kurus::kurus(number_format($tl, 2, '.', ''));
        $fark = $kurus($gelir['toplam']) - $kurus($gider['toplam']);

        return [
            'donem' => $donem,
            'etiket' => Donem::etiket($donem),
            'gelir' => $gelir,
            'gider' => $gider,
            'faturalar' => $this->faturaOzeti($haneId, $donem),
            // Toplamlar kuruşun yüzde biri (x/100): iki haneyle metne çevrilince kuruş tam geri gelir
            'tasarruf' => $tasarruf,
            // Altın / döviz günleri: bu ay beklenen / yapılan alımlar (bilgi; toplamlara girmez)
            'gunler' => $this->gunTakvimi->ayinGunleri($haneId, $donem),
            'fark' => Kurus::tl($fark),
            // Tasarruf gider değildir: Gelir − Gider − Tasarruf = Kalan
            'kalan' => Kurus::tl($fark - $kurus($tasarruf['toplam'])),
            // Yalnızca bilgi: verilen zekât gider / kalan hesabına girmez
            'zekat' => $this->zekat->verilen($haneId, $donem),
        ];
    }

    /**
     * Ayın tasarrufu: portföy hesabına yazılan alışlar (tarihin ayı). Gider toplamına girmez.
     *
     * @return array<string, mixed>
     */
    public function tasarrufOzeti(int $haneId, string $donem): array
    {
        $kayitlar = Tasarruf::where('hane_id', $haneId)->where('donem', $donem)->with(['hesap', 'islem.varlik', 'gun'])
            ->orderByDesc('tarih')->orderByDesc('id')->get();

        return [
            'toplam' => Kurus::tl($kayitlar->reduce(fn (int $t, Tasarruf $s): int => $t + Kurus::kurus($s->tutar_tl), 0)),
            'kalemler' => $kayitlar->map(fn (Tasarruf $s): array => $s->ozet())->values(),
        ];
    }

    /**
     * Faturalar hizmet ayına göre (ödendiği aya göre değil). Bilgi amaçlı: kartla ödenenler kart harcamasında,
     * kartsız ödenenler ödendiği ayın giderinde zaten var; burada ikinci kez gidere eklenmez.
     *
     * @return array<string, mixed>
     */
    public function faturaOzeti(int $haneId, string $donem): array
    {
        $faturalar = Fatura::where('hane_id', $haneId)->where('hizmet_donemi', $donem)->get();
        $turler = FaturaTuru::haneIcin($haneId)->get()->keyBy('id');
        $topla = fn (Collection $c): int => $c->reduce(fn (int $t, Fatura $f): int => $t + Kurus::kurus($f->tutar), 0);

        return [
            'toplam' => Kurus::tl($topla($faturalar)),
            'odenen' => Kurus::tl($topla($faturalar->where('durum', FaturaDurumu::Odendi))),
            'bekleyen' => Kurus::tl($topla($faturalar->where('durum', FaturaDurumu::Bekliyor))),
            'kartla_odenen' => Kurus::tl($topla($faturalar->whereNotNull('kart_islemi_id'))),
            'turler' => $faturalar->groupBy('fatura_turu_id')->map(fn (Collection $grup, int $turId): array => [
                'fatura_turu_id' => $turId,
                'ad' => $turler[$turId]->ad ?? '',
                'ikon' => $turler[$turId]->ikon ?? null,
                'toplam' => Kurus::tl($topla($grup)),
                'adet' => $grup->count(),
            ])->sortByDesc('toplam')->values(),
            // Hizmet ayı bilinmeyen faturalar (hiçbir hizmet ayına yazılmadı; kullanıcının seçmesi beklenir)
            'donemi_bilinmeyen' => Fatura::where('hane_id', $haneId)->whereNull('hizmet_donemi')->count(),
        ];
    }

    /**
     * Uyarılar: gecikmiş ve 10 gün içinde son ödemesi olan bekleyen faturalar; son ödeme tarihi 10 gün içinde (ya da
     * en çok 5 gün geçmiş) olan, ödendi işaretlenmemiş ekstreler.
     *
     * @return array<string, mixed>
     */
    public function uyarilar(int $haneId): array
    {
        $bugun = now()->startOfDay();
        $ekstreler = Ekstre::where('hane_id', $haneId)->where('durum', EkstreDurumu::Onaylandi->value)->where('odendi', false)
            ->whereBetween('son_odeme_tarihi', [$bugun->copy()->subDays(5)->toDateString(), $bugun->copy()->addDays(10)->toDateString()])
            ->with('kart')->orderBy('son_odeme_tarihi')->get();
        $bekleyen = Fatura::where('hane_id', $haneId)->where('durum', FaturaDurumu::Bekliyor->value)
            ->with('saglayici')->orderByRaw('son_odeme_tarihi is null')->orderBy('son_odeme_tarihi')->get();
        $faturaSatiri = fn (Fatura $f): array => [
            ...$f->ozet(),
            'saglayici_ad' => $f->saglayici->ad,
            'gun' => $f->son_odeme_tarihi ? (int) $bugun->diffInDays($f->son_odeme_tarihi, false) : null,
        ];

        return [
            'gecikmis_faturalar' => $bekleyen->filter(fn (Fatura $f): bool => $f->gecikmisMi())->map($faturaSatiri)->values(),
            'bekleyen_faturalar' => $bekleyen->reject(fn (Fatura $f): bool => $f->gecikmisMi())
                ->filter(fn (Fatura $f): bool => $f->son_odeme_tarihi === null || $f->son_odeme_tarihi->lte($bugun->copy()->addDays(10)))
                ->map($faturaSatiri)->values(),
            'son_odemeler' => $ekstreler->map(fn (Ekstre $e): array => [
                ...$e->ozet(),
                'kart_ad' => $e->kart->ad,
                'gun' => (int) $bugun->diffInDays($e->son_odeme_tarihi, false),
            ])->values(),
            // Zekât hatırlatmaları (gün yaklaşıyor / geçti / ödenmemiş zekât); Bütçe toplamlarına girmez
            'zekat' => $this->zekat->uyarilar($haneId),
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
