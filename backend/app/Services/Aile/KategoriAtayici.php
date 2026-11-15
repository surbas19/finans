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
 * rapor dönemi ve raporlar etkilenmez.
 */
class KategoriAtayici
{
    public const DESEN_EN_COK = 60;

    public function __construct(private KategoriBulucu $bulucu) {}

    /**
     * @return int güncellenen satır sayısı (işlemin kendisi dahil)
     */
    public function ata(KartIslemi $islem, ?HarcamaKategorisi $kategori): int
    {
        $haneId = (int) $islem->hane_id;
        $sade = Metin::sade((string) $islem->aciklama);
        $desen = $this->desen($sade);

        $guncellenen = DB::transaction(function () use ($islem, $kategori, $haneId, $sade, $desen): int {
            if ($desen !== '') {
                if ($kategori !== null) {
                    KategoriKurali::updateOrCreate(['hane_id' => $haneId, 'desen' => $desen], ['kategori_id' => $kategori->id]);
                } else {
                    KategoriKurali::where('hane_id', $haneId)->where('desen', $desen)->delete();
                }
            }
            $idler = $sade === '' ? [$islem->id] : $this->ayniAciklamali($haneId, $sade);
            foreach (array_chunk($idler, 500) as $parca) {
                KartIslemi::whereIn('id', $parca)->update(['kategori_id' => $kategori?->id]);
            }

            return count($idler);
        });
        $this->bulucu->unut($haneId);

        return $guncellenen;
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
