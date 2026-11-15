<?php

use App\Models\ZekatOdemesi;
use App\Services\Zekat\ZekatDonemleri;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    /**
     * İşlemler'den girilen zekât ödemeleri yıla bağsızdı: tarihlerini kapsayan (Ramazan'dan Ramazan'a) kesinleşmiş zekât
     * yılına bağlanır. Kesinleşmiş yıl yoksa bağsız kalır; hiçbir tutar ya da tarih değişmez.
     */
    public function up(): void
    {
        $donemler = app(ZekatDonemleri::class);
        ZekatOdemesi::whereNull('zekat_yili_id')->get()->each(function (ZekatOdemesi $o) use ($donemler): void {
            $yil = $donemler->kayitBul((int) $o->hane_id, Carbon::parse($o->tarih));
            if ($yil !== null) {
                $o->forceFill(['zekat_yili_id' => $yil->id])->save();
            }
        });
    }

    public function down(): void
    {
        // Bağlama geri alınmaz: ödeme kayıtları aynı kalır, yalnız yıl bağı vardı
    }
};
