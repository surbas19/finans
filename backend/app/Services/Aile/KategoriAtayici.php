<?php

namespace App\Services\Aile;

use App\Models\HarcamaKategorisi;
use App\Models\KartIslemi;
use App\Models\KategoriKurali;
use App\Support\Metin;
use Illuminate\Support\Facades\DB;

/**
 * Bir kart satırını kategorilemek: aynı açıklamalı (Metin::sade eşitliği) hanenin TÜM kart satırları aynı kategoriye
 * geçer (mevcut kategori ne olursa olsun) ve açıklama bir kural olarak kaydedilir, böylece sonraki ekstrelerde de
 * otomatik uygulanır (KategoriBulucu). Kategorisiz yapmak kuralı kaldırır. Yalnızca kategori_id değişir; tutar,
 * rapor dönemi ve raporlar etkilenmez. "Yalnız bu işlem" kapsamında yalnız o satır değişir, kural değişmez. Atama geri
 * alınabilir (satırların önceki kategorileri ve kuralın önceki hâli yanıtta döner). Seçicideki öneri de burada.
 */
class KategoriAtayici
{
    public const DESEN_EN_COK = 60;

    public function __construct(private KategoriBulucu $bulucu) {}

    /**
     * Kategori atar. $tek: yalnız bu satır (kural değişmez); değilse aynı açıklamalı bütün satırlar + kural.
     *
     * @return array{sayi: int, geri_al: array{gruplar: list<array{kategori_id: int|null, idler: list<int>}>, kural: array{var: bool, kategori_id: int|null}|null}}
     */
    public function ata(KartIslemi $islem, ?HarcamaKategorisi $kategori, bool $tek = false): array
    {
        $haneId = (int) $islem->hane_id;
        $sade = Metin::sade((string) $islem->aciklama);
        $desen = $tek ? '' : $this->desen($sade);

        $sonuc = DB::transaction(function () use ($islem, $kategori, $haneId, $sade, $desen, $tek): array {
            $kural = null;
            if ($desen !== '') {
                $eski = KategoriKurali::where('hane_id', $haneId)->where('desen', $desen)->first();
                $kural = ['var' => $eski !== null, 'kategori_id' => $eski?->kategori_id];
                if ($kategori !== null) {
                    KategoriKurali::updateOrCreate(['hane_id' => $haneId, 'desen' => $desen], ['kategori_id' => $kategori->id]);
                } else {
                    $eski?->delete();
                }
            }
            $idler = $tek || $sade === '' ? [(int) $islem->id] : $this->ayniAciklamali($haneId, $sade);
            $gruplar = [];
            foreach (array_chunk($idler, 500) as $parca) {
                foreach (KartIslemi::whereIn('id', $parca)->get(['id', 'kategori_id']) as $s) {
                    $gruplar[$s->kategori_id ?? 'yok'][] = (int) $s->id;
                }
                KartIslemi::whereIn('id', $parca)->update(['kategori_id' => $kategori?->id]);
            }
            $liste = [];
            foreach ($gruplar as $k => $ids) {
                $liste[] = ['kategori_id' => $k === 'yok' ? null : (int) $k, 'idler' => $ids];
            }

            return ['sayi' => count($idler), 'geri_al' => ['gruplar' => $liste, 'kural' => $kural]];
        });
        $this->bulucu->unut($haneId);

        return $sonuc;
    }

    /**
     * Atamayı geri alır: satırlar önceki kategorilerine, kural önceki hâline (kural null: kurala dokunulmaz). Satırların
     * ve kategorilerin hanede olduğunu çağıran denetler; güncelleme yine de hane ile sınırlıdır.
     *
     * @param  list<array{kategori_id: int|null, idler: list<int>}>  $gruplar
     * @param  array{var: bool, kategori_id: int|null}|null  $kural
     */
    public function geriAl(KartIslemi $islem, array $gruplar, ?array $kural): void
    {
        $haneId = (int) $islem->hane_id;
        $desen = $this->desen(Metin::sade((string) $islem->aciklama));
        DB::transaction(function () use ($gruplar, $kural, $haneId, $desen): void {
            foreach ($gruplar as $g) {
                foreach (array_chunk($g['idler'], 500) as $parca) {
                    KartIslemi::where('hane_id', $haneId)->whereIn('id', $parca)->update(['kategori_id' => $g['kategori_id']]);
                }
            }
            if ($kural !== null && $desen !== '') {
                if ($kural['var'] && $kural['kategori_id'] !== null) {
                    KategoriKurali::updateOrCreate(['hane_id' => $haneId, 'desen' => $desen], ['kategori_id' => $kural['kategori_id']]);
                } else {
                    KategoriKurali::where('hane_id', $haneId)->where('desen', $desen)->delete();
                }
            }
        });
        $this->bulucu->unut($haneId);
    }

    /** Aynı açıklamalı satır sayısı (kendisi dahil; açıklaması boşsa 1) */
    public function ayniAciklamaliSayisi(KartIslemi $islem): int
    {
        $sade = Metin::sade((string) $islem->aciklama);

        return $sade === '' ? 1 : count($this->ayniAciklamali((int) $islem->hane_id, $sade));
    }

    /**
     * Önerilen kategori: önce hanenin kuralları (KategoriBulucu), yoksa açıklamasının ilk kelimesi (3+ harf, sayı değil)
     * aynı olan, açıklaması farklı kategorili satırlarda en çok kullanılan kategori. Mevcut kategoriyle aynıysa ya da
     * dayanak yoksa null (uydurulmaz).
     *
     * @return array{kategori_id: int, gerekce: string, adet: int|null}|null
     */
    public function oneri(KartIslemi $islem): ?array
    {
        $haneId = (int) $islem->hane_id;
        $sade = Metin::sade((string) $islem->aciklama);
        $mevcut = $islem->kategori_id !== null ? (int) $islem->kategori_id : null;

        $kural = $this->bulucu->bul($haneId, (string) $islem->aciklama);
        if ($kural !== null) {
            return (int) $kural === $mevcut ? null : ['kategori_id' => (int) $kural, 'gerekce' => 'kural', 'adet' => null];
        }

        $ilk = explode(' ', $sade)[0];
        if (mb_strlen($ilk) < 3 || preg_match('/^[0-9]+$/', $ilk) === 1) {
            return null;
        }
        $sayac = [];
        $satirlar = KartIslemi::where('hane_id', $haneId)->whereNotNull('kategori_id')->select(['id', 'aciklama', 'kategori_id'])->cursor();
        foreach ($satirlar as $s) {
            $o = Metin::sade((string) $s->aciklama);
            if ($o !== $sade && explode(' ', $o)[0] === $ilk) {
                $sayac[(int) $s->kategori_id] = ($sayac[(int) $s->kategori_id] ?? 0) + 1;
            }
        }
        if ($sayac === []) {
            return null;
        }
        arsort($sayac);
        $en = (int) array_key_first($sayac);

        return $en === $mevcut ? null : ['kategori_id' => $en, 'gerekce' => 'benzer', 'adet' => $sayac[$en]];
    }

    /**
     * @return list<int>
     */
    private function ayniAciklamali(int $haneId, string $sade): array
    {
        $idler = [];
        foreach (KartIslemi::where('hane_id', $haneId)->select(['id', 'aciklama'])->cursor() as $satir) {
            if (Metin::sade((string) $satir->aciklama) === $sade) {
                $idler[] = (int) $satir->id;
            }
        }

        return $idler;
    }

    /** Kural deseni: sade açıklama, en çok 60 karakter (uzunsa kelime sınırında kısaltılır); 2 karakterden kısaysa kural yok */
    private function desen(string $sade): string
    {
        if (mb_strlen($sade) > self::DESEN_EN_COK) {
            $kes = mb_substr($sade, 0, self::DESEN_EN_COK);
            $bosluk = mb_strrpos($kes, ' ');
            $sade = $bosluk !== false && $bosluk >= 2 ? mb_substr($kes, 0, $bosluk) : $kes;
        }

        return mb_strlen($sade) >= 2 ? $sade : '';
    }
}
