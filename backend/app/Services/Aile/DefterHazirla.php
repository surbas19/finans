<?php

namespace App\Services\Aile;

use App\Enums\EkstreDurumu;
use App\Enums\FaturaDurumu;
use App\Enums\IslemTuru;
use App\Enums\KartIslemDurumu;
use App\Models\Borc;
use App\Models\BorcOdemesi;
use App\Models\DuzenliGider;
use App\Models\Ekstre;
use App\Models\Fatura;
use App\Models\FiyatGecmisi;
use App\Models\Gelir;
use App\Models\HarcamaKategorisi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\KartIslemi;
use App\Models\Tasarruf;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\Pozisyon;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Donem;
use App\Support\Kurus;
use Carbon\CarbonInterface;

/**
 * Defter içeriğinin TEK hesaplayıcısı: bir hane ve ay için kategorili kayıt listesi, temettü, borç / alacak ve
 * kalemlerin (varlıkların) adet + değer değişimi. Toplamlar `ButceRaporu`'ndan gelir (ikinci bir kural yoktur);
 * kayıt satırları yalnızca listedir, kategori toplamıyla farkı "Diğer" satırı kapatır. Kapanmış ayda sonuç
 * `DefterKapanisi` ile dondurulur.
 */
class DefterHazirla
{
    public function __construct(private ButceRaporu $butce) {}

    /**
     * @return array<string, mixed>
     */
    public function olustur(int $haneId, string $donem): array
    {
        $ozet = $this->butce->aylikOzet($haneId, $donem);
        $adlar = User::where('hane_id', $haneId)->get(['id', 'ad', 'soyad'])
            ->mapWithKeys(fn (User $u): array => [$u->id => trim($u->ad.' '.$u->soyad)])->all();
        $yazan = function (?int $id, ?CarbonInterface $zaman) use ($adlar): string {
            return ($id !== null ? ($adlar[$id] ?? 'Bilinmiyor') : 'Sistem').($zaman ? ' · '.$zaman->format('d.m H:i') : '');
        };

        $gruplar = [];
        $gruplar[] = $this->gelirGrubu($haneId, $donem, $ozet, $yazan);
        foreach ($this->giderGruplari($haneId, $donem, $ozet, $yazan) as $grup) {
            $gruplar[] = $grup;
        }
        $gruplar[] = $this->tasarrufGrubu($haneId, $donem, $ozet, $yazan);

        $hesapIdleri = Hesap::whereIn('user_id', array_keys($adlar))->pluck('id')->all();

        return [
            'donem' => $donem,
            'etiket' => Donem::etiket($donem),
            'ozet' => [
                'gelir' => $ozet['gelir']['toplam'],
                'gider' => $ozet['gider']['toplam'],
                'tasarruf' => $ozet['tasarruf']['toplam'],
                'kalan' => $ozet['kalan'],
            ],
            'gruplar' => $gruplar,
            'temettu' => $this->temettu($hesapIdleri, $donem, $yazan),
            'borclar' => $this->borclar($haneId, $donem, $yazan),
            'yatirim' => $this->yatirim($hesapIdleri, $donem, $yazan),
        ];
    }

    /**
     * @param  array<string, mixed>  $ozet
     * @return array<string, mixed>
     */
    private function gelirGrubu(int $haneId, string $donem, array $ozet, callable $yazan): array
    {
        $satirlar = Gelir::where('hane_id', $haneId)->where('donem', $donem)->orderBy('tarih')->orderBy('id')->get()
            ->map(fn (Gelir $g): array => [
                'tarih' => $g->tarih->toDateString(),
                'ad' => $g->aciklama ?: 'Gelir',
                'tutar' => Kurus::tl(Kurus::kurus($g->tl_tutar)),
                'yazan' => $yazan($g->olusturan_id, $g->created_at),
                'not' => $g->para_birimi !== 'TRY' ? $g->tutar.' '.$g->para_birimi : null,
            ])->values()->all();

        return ['id' => 'gelir', 'ad' => 'Gelir', 'tur' => 'gelir', 'toplam' => $ozet['gelir']['toplam'], 'satirlar' => $satirlar];
    }

    /**
     * Kategori toplamları ButceRaporu'ndan; satırlar kart satırı + düzenli / nakit gider + kartsız fatura. Satır toplamı
     * kategori toplamından farklıysa fark "Diğer" satırıdır (ekstre dönem borcu farkı, 35 gün kuralı…).
     *
     * @param  array<string, mixed>  $ozet
     * @return list<array<string, mixed>>
     */
    private function giderGruplari(int $haneId, string $donem, array $ozet, callable $yazan): array
    {
        $gruplar = [];
        foreach ($ozet['gider']['kategoriler'] as $k) {
            $anahtar = $k['diger'] ? 'diger' : 'k'.($k['kategori_id'] ?? 0);
            $gruplar[$anahtar] = ['id' => $anahtar, 'ad' => $k['ad'], 'renk' => $k['renk'], 'tur' => 'gider', 'toplam' => $k['toplam'], 'satirlar' => []];
        }
        $anahtarOf = fn (?int $kategori): string => 'k'.($kategori ?? 0);

        $ekstreIdleri = Ekstre::where('hane_id', $haneId)->where('durum', EkstreDurumu::Onaylandi->value)->ayinda($donem)->pluck('id');
        KartIslemi::where('hane_id', $haneId)->where('durum', KartIslemDurumu::Onayli->value)
            ->where(fn ($q) => $q->whereIn('ekstre_id', $ekstreIdleri)->orWhere(fn ($e) => $e->whereNull('ekstre_id')->where('rapor_donemi', $donem)))
            ->with(['iadeEdilen:id,kategori_id', 'kart:id,ad'])->orderBy('islem_tarihi')->orderBy('id')->get()
            ->each(function (KartIslemi $i) use (&$gruplar, $anahtarOf, $yazan): void {
                $etki = $this->butce->giderEtkisi($i);
                $anahtar = $anahtarOf($i->kategori_id ?? $i->iadeEdilen?->kategori_id);
                if ($etki === 0 || ! isset($gruplar[$anahtar])) {
                    return;
                }
                $gruplar[$anahtar]['satirlar'][] = [
                    'tarih' => $i->islem_tarihi?->toDateString(),
                    'ad' => $i->aciklama,
                    'tutar' => Kurus::tl($etki),
                    'yazan' => $yazan($i->olusturan_id, $i->created_at),
                    'not' => $i->kart?->ad,
                ];
            });

        DuzenliGider::where('hane_id', $haneId)->where('donem', $donem)->orderBy('tarih')->orderBy('id')->get()
            ->each(function (DuzenliGider $g) use (&$gruplar, $anahtarOf, $yazan): void {
                $anahtar = $anahtarOf($g->kategori_id);
                if (! isset($gruplar[$anahtar])) {
                    return;
                }
                $gruplar[$anahtar]['satirlar'][] = [
                    'tarih' => $g->tarih->toDateString(),
                    'ad' => $g->aciklama ?: 'Gider',
                    'tutar' => Kurus::tl(Kurus::kurus($g->tutar)),
                    'yazan' => $yazan($g->olusturan_id, $g->created_at),
                    'not' => $g->gider_kaynagi_id === null ? 'nakit harcama' : 'düzenli ödeme',
                ];
            });

        $faturaKategorisi = HarcamaKategorisi::where('hane_id', $haneId)->where('fatura', true)->value('id');
        $faturaAnahtar = $anahtarOf($faturaKategorisi ? (int) $faturaKategorisi : null);
        Fatura::where('hane_id', $haneId)->where('durum', FaturaDurumu::Odendi->value)->whereNull('kart_islemi_id')
            ->whereBetween('odeme_tarihi', [Donem::ilkGun($donem)->toDateString(), Donem::sonGun($donem)->toDateString()])
            ->with('saglayici:id,ad')->orderBy('odeme_tarihi')->get()
            ->each(function (Fatura $f) use (&$gruplar, $faturaAnahtar): void {
                if (! isset($gruplar[$faturaAnahtar])) {
                    return;
                }
                $gruplar[$faturaAnahtar]['satirlar'][] = [
                    'tarih' => $f->odeme_tarihi?->toDateString(),
                    'ad' => ($f->saglayici?->ad ?? 'Fatura').' faturası',
                    'tutar' => Kurus::tl(Kurus::kurus($f->tutar)),
                    'yazan' => 'Fatura kaydı',
                    'not' => 'kartsız ödendi',
                ];
            });

        foreach ($gruplar as $anahtar => &$grup) {
            if ($anahtar === 'diger') {
                continue;
            }
            $satirToplam = collect($grup['satirlar'])->sum(fn (array $s): int => Kurus::kurus($s['tutar']));
            $fark = Kurus::kurus(number_format((float) $grup['toplam'], 2, '.', '')) - $satirToplam;
            if ($fark !== 0) {
                $grup['satirlar'][] = ['tarih' => null, 'ad' => 'Diğer (ekstre farkı, devreden)', 'tutar' => Kurus::tl($fark), 'yazan' => 'Hesaplandı', 'not' => null];
            }
        }
        unset($grup);

        return array_values($gruplar);
    }

    /**
     * @param  array<string, mixed>  $ozet
     * @return array<string, mixed>
     */
    private function tasarrufGrubu(int $haneId, string $donem, array $ozet, callable $yazan): array
    {
        $satirlar = Tasarruf::where('hane_id', $haneId)->where('donem', $donem)->with(['islem.varlik', 'varlik'])->orderBy('tarih')->orderBy('id')->get()
            ->map(function (Tasarruf $s) use ($yazan): array {
                $o = $s->ozet();

                return [
                    'tarih' => $o['tarih'],
                    'ad' => trim(($o['varlik_ad'] ?? 'Tasarruf').' '.($o['miktar'] !== null ? rtrim(rtrim(number_format((float) $o['miktar'], 4, '.', ''), '0'), '.') : '')),
                    'tutar' => $o['tutar'],
                    'yazan' => $yazan($s->olusturan_id, $s->created_at),
                    'not' => $o['not'],
                ];
            })->values()->all();

        return ['id' => 'tasarruf', 'ad' => 'Tasarruf', 'tur' => 'tasarruf', 'toplam' => $ozet['tasarruf']['toplam'], 'satirlar' => $satirlar];
    }

    /**
     * Hisse temettüleri (net): bütçe gelirine girmez.
     *
     * @param  list<int>  $hesapIdleri
     * @return array<string, mixed>
     */
    private function temettu(array $hesapIdleri, string $donem, callable $yazan): array
    {
        $satirlar = Islem::whereIn('hesap_id', $hesapIdleri)->where('tur', IslemTuru::Temettu->value)->where('dahil', true)
            ->whereBetween('tarih', [Donem::ilkGun($donem)->toDateString(), Donem::sonGun($donem)->toDateString()])
            ->with('varlik:id,kod,ad')->orderBy('tarih')->orderBy('id')->get()
            ->map(fn (Islem $i): array => [
                'tarih' => $i->tarih->toDateString(),
                'ad' => ($i->varlik?->kod ?? 'Hisse').' temettüsü',
                'tutar' => round($i->miktar, 2),
                'yazan' => $yazan($i->user_id, $i->created_at),
                'not' => 'brüt '.round($i->miktar + $i->komisyon, 2).' · stopaj '.round($i->komisyon, 2),
            ])->values()->all();

        return ['toplam' => round(collect($satirlar)->sum('tutar'), 2), 'satirlar' => $satirlar];
    }

    /**
     * Ay içinde açılan ya da ödemesi olan borç / alacak kayıtları; kalan ay sonu itibarıyladır. Bütçeye karışmaz.
     *
     * @return array<string, mixed>
     */
    private function borclar(int $haneId, string $donem, callable $yazan): array
    {
        $bas = Donem::ilkGun($donem)->toDateString();
        $son = Donem::sonGun($donem)->toDateString();
        $kayitlar = Borc::where('hane_id', $haneId)->whereDate('tarih', '<=', $son)->with('odemeler')->orderBy('tarih')->orderBy('id')->get()
            ->filter(fn (Borc $b): bool => $b->tarih->toDateString() >= $bas || $b->odemeler->contains(fn (BorcOdemesi $o): bool => $o->tarih->toDateString() >= $bas && $o->tarih->toDateString() <= $son));
        $alacak = 0;
        $borc = 0;
        $liste = $kayitlar->map(function (Borc $b) use ($son, $yazan, &$alacak, &$borc): array {
            $odemeler = $b->odemeler->filter(fn (BorcOdemesi $o): bool => $o->tarih->toDateString() <= $son)->values();
            $kalan = Kurus::kurus($b->tutar) - $odemeler->sum(fn (BorcOdemesi $o): int => Kurus::kurus($o->tutar));
            if ($b->yon === Borc::ALACAK) {
                $alacak += $kalan;
            } else {
                $borc += $kalan;
            }

            return [
                'yon' => $b->yon,
                'kisi' => $b->kisi,
                'tarih' => $b->tarih->toDateString(),
                'tutar' => Kurus::tl(Kurus::kurus($b->tutar)),
                'yazan' => $yazan($b->olusturan_id, $b->created_at),
                'kalan' => Kurus::tl($kalan),
                'odemeler' => $odemeler->map(fn (BorcOdemesi $o): array => ['tarih' => $o->tarih->toDateString(), 'tutar' => Kurus::tl(Kurus::kurus($o->tutar)), 'yazan' => $yazan(null, $o->created_at)])->all(),
            ];
        })->values()->all();

        return ['alacak' => Kurus::tl($alacak), 'borc' => Kurus::tl($borc), 'kayitlar' => $liste];
    }

    /**
     * Kalemler: hanenin tüm hesaplarında varlık başına önceki ay sonu ve ay sonu adedi, birim fiyatı, değeri, ayın alım /
     * satımları ve değer değişiminin "sizin işleminiz" ile "fiyat hareketi" olarak ayrımı. Fiyatı bulunamayan değer null.
     *
     * @param  list<int>  $hesapIdleri
     * @return array<string, mixed>
     */
    private function yatirim(array $hesapIdleri, string $donem, callable $yazan): array
    {
        $ilk = Donem::ilkGun($donem);
        $son = Donem::sonGun($donem);
        $bitis = $son->isFuture() ? now()->startOfDay() : $son;
        $onceki = $ilk->copy()->subDay();
        $islemler = PortfoyHesabi::sirala(
            Islem::whereIn('hesap_id', $hesapIdleri)->where('dahil', true)->whereDate('tarih', '<=', $son->toDateString())->with('varlik')->get()
        );
        $miktar = function (?CarbonInterface $sinir) use ($islemler): array {
            $poz = [];
            $bozuk = [];
            foreach ($islemler as $i) {
                if ($sinir !== null && $i->tarih->gt($sinir)) {
                    continue;
                }
                $anahtar = $i->hesap_id.'-'.$i->varlik_id;
                if (isset($bozuk[$anahtar])) {
                    continue;
                }
                $poz[$anahtar] ??= new Pozisyon($i->hesap_id, $i->varlik_id);
                try {
                    $poz[$anahtar]->uygula($i);
                } catch (YetersizMiktar) {
                    $bozuk[$anahtar] = true;
                }
            }
            $toplam = [];
            foreach ($poz as $p) {
                $toplam[$p->varlikId] = ($toplam[$p->varlikId] ?? 0) + $p->miktar();
            }

            return $toplam;
        };
        $ilkMiktar = $miktar($onceki);
        $sonMiktar = $miktar($bitis);

        $ayIslemleri = $islemler->filter(fn (Islem $i): bool => in_array($i->tur, [IslemTuru::Alis, IslemTuru::Satis], true)
            && $i->tarih->gte($ilk) && $i->tarih->lte($son));
        $temettuler = $islemler->filter(fn (Islem $i): bool => $i->tur === IslemTuru::Temettu && $i->tarih->gte($ilk) && $i->tarih->lte($son));
        $varlikIdleri = collect(array_keys($ilkMiktar))->merge(array_keys($sonMiktar))->merge($ayIslemleri->pluck('varlik_id'))->unique();
        $varliklar = Varlik::whereIn('id', $varlikIdleri)->with('fiyat')->get()->keyBy('id');
        $usdId = Varlik::where('tur', 'doviz')->where('kod', 'USD')->value('id');

        $fiyatTl = function (Varlik $v, CarbonInterface $tarih) use ($usdId): ?float {
            $kayit = fn (int $id): ?float => FiyatGecmisi::where('varlik_id', $id)->whereDate('tarih', '<=', $tarih->toDateString())->orderByDesc('tarih')->value('kapanis');
            $fiyat = $kayit($v->id) ?? ($tarih->gte(now()->startOfDay()) ? $v->fiyat?->fiyat : null);
            if ($fiyat === null) {
                return null;
            }

            return match ($v->para_birimi) {
                'TRY' => (float) $fiyat,
                'USD' => ($usd = $usdId ? ($kayit((int) $usdId) ?? ($tarih->gte(now()->startOfDay()) ? Varlik::find($usdId)?->fiyat?->fiyat : null)) : null) ? (float) $fiyat * $usd : null,
                default => null,
            };
        };

        $kalemler = [];
        foreach ($varliklar as $v) {
            if ($v->tur->sabitFiyatli()) {
                continue;
            }
            $i0 = round($ilkMiktar[$v->id] ?? 0.0, 8);
            $i1 = round($sonMiktar[$v->id] ?? 0.0, 8);
            $kendi = $ayIslemleri->where('varlik_id', $v->id)->values();
            if ($i0 === 0.0 && $i1 === 0.0 && $kendi->isEmpty()) {
                continue;
            }
            $f0 = $fiyatTl($v, $onceki);
            $f1 = $fiyatTl($v, $bitis);
            $islemListesi = $kendi->map(function (Islem $i) use ($yazan, $v, $fiyatTl): array {
                $katsayi = $v->para_birimi === 'TRY' ? 1.0 : ($fiyatTl($v, $i->tarih) !== null && $v->fiyat?->fiyat ? $fiyatTl($v, $i->tarih) / (float) $v->fiyat->fiyat : null);

                return [
                    'tarih' => $i->tarih->toDateString(),
                    'adet' => $i->tur === IslemTuru::Alis ? round($i->miktar, 8) : -round($i->miktar, 8),
                    'fiyat' => $i->birim_fiyat !== null ? round($i->birim_fiyat * ($katsayi ?? 1), 4) : null,
                    'yazan' => $yazan($i->user_id, $i->created_at),
                ];
            })->all();
            $net = collect($islemListesi)->sum(fn (array $s): float => $s['fiyat'] !== null ? $s['adet'] * $s['fiyat'] : 0.0);
            $d0 = $f0 !== null ? round($i0 * $f0, 2) : null;
            $d1 = $f1 !== null ? round($i1 * $f1, 2) : null;
            $kalemler[] = [
                'varlik_id' => $v->id,
                'kod' => $v->kod,
                'ad' => $v->ad,
                'tur' => $v->tur->value,
                'birim' => $v->birim,
                'ilk' => $i0,
                'son' => $i1,
                'fiyat_ilk' => $f0 !== null ? round($f0, 4) : null,
                'fiyat_son' => $f1 !== null ? round($f1, 4) : null,
                'deger_ilk' => $d0,
                'deger_son' => $d1,
                'islemler' => $islemListesi,
                'net_islem' => round($net, 2),
                'fiyat_etkisi' => $d0 !== null && $d1 !== null ? round($d1 - $d0 - $net, 2) : null,
                'temettu' => round($temettuler->where('varlik_id', $v->id)->sum('miktar'), 2),
            ];
        }
        usort($kalemler, fn (array $a, array $b): int => ($b['deger_son'] ?? 0) <=> ($a['deger_son'] ?? 0));

        return ['kalemler' => $kalemler];
    }
}
