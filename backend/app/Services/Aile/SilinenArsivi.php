<?php

namespace App\Services\Aile;

use App\Models\Concerns\Arsivlenir;
use App\Models\SilinenKaydi;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Silinen kayıtların arşivi (tek yer). Kullanıcının sildiği model kaydı gitmeden önce anlık görüntüsü ve bağlı kayıtlarının
 * özeti `silinen_kayitlar`'a yazılır: ne, kim yaptı / ne zaman, kim sildi / ne zaman. `sessiz()` içinde yapılan silmeler
 * (ana kaydın zaten arşivlediği bağlı kayıtlar) ikinci kez yazılmaz. Arşiv hiçbir yolla silinmez. Kişisel veri alanı
 * yoktur (zaten tablolarda olanlar kopyalanır); içerik loglanmaz.
 */
class SilinenArsivi
{
    private static int $sessiz = 0;

    /** Bu blokta yapılan model silmeleri arşivlenmez (bağlı kayıt ana kaydın `alt` alanındadır) */
    public static function sessiz(callable $is): mixed
    {
        self::$sessiz++;
        try {
            return $is();
        } finally {
            self::$sessiz--;
        }
    }

    /** @param  Model&Arsivlenir  $kayit */
    public function yaz(Model $kayit): void
    {
        if (self::$sessiz > 0 || ! $kayit->arsivlenecekMi()) {
            return;
        }
        $silen = auth()->user();
        $olusturanId = $kayit->arsivOlusturanId();
        $olusturan = $olusturanId !== null ? User::find($olusturanId) : null;
        $zaman = $kayit->getAttribute('created_at') ?? $kayit->getAttribute('olusturuldu');

        SilinenKaydi::create([
            'hane_id' => $kayit->arsivHaneId(),
            'tur' => $kayit->arsivTuru(),
            'kayit_id' => (int) $kayit->getKey(),
            'baslik' => mb_substr($kayit->arsivBaslik(), 0, 255),
            'tutar' => $kayit->arsivTutar(),
            'icerik' => collect($kayit->getAttributes())->except($kayit->arsivHaric())->all(),
            'alt' => $kayit->arsivAltGecici,
            'neden' => $kayit->arsivNeden(),
            'olusturan_id' => $olusturan?->id,
            'olusturan_ad' => $olusturan ? trim($olusturan->ad.' '.$olusturan->soyad) : null,
            'olusturuldu_at' => $zaman,
            'silen_id' => $silen?->id,
            'silen_ad' => $silen ? trim($silen->ad.' '.$silen->soyad) : 'Sistem',
            'silindi_at' => now(),
        ]);
    }
}
