<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Veri tamamlama: kural "hizmet dönemi açıklamada yoksa ekstrenin kesim ayı" olmadan önce onaylanan ekstrelerin
 * dönemi bilinmeyen faturaları, bağlı oldukları satırın ekstresinin kesim ayını alır (kaynağı "kesim"). O sağlayıcının
 * o ayda başka faturası varsa dokunulmaz (aynı dönem iki kez olmaz). Şema değişmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        $adaylar = DB::table('faturalar as f')
            ->join('kart_islemleri as k', 'k.id', '=', 'f.kart_islemi_id')
            ->join('ekstreler as e', 'e.id', '=', 'k.ekstre_id')
            ->whereNull('f.hizmet_donemi')
            ->whereNotNull('e.kesim_tarihi')
            ->orderBy('f.id')
            ->get(['f.id', 'f.saglayici_id', 'e.kesim_tarihi']);

        foreach ($adaylar as $aday) {
            $donem = substr((string) $aday->kesim_tarihi, 0, 7); // YYYY-AA
            $dolu = DB::table('faturalar')->where('saglayici_id', $aday->saglayici_id)->where('hizmet_donemi', $donem)->exists();
            if (! $dolu) {
                DB::table('faturalar')->where('id', $aday->id)->update([
                    'hizmet_donemi' => $donem,
                    'donem_kaynagi' => 'kesim',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Veri tamamlaması geri alınmaz: kaynağı "kesim" olan faturanın dönemi Faturalar'dan değiştirilebilir ya da
        // boşaltılabilir.
    }
};
