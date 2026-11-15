<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gelir türlerine "Ek ders" eklenir: her hanede Maaş'ın hemen ardına (Maaş'ın sırası + 1; sonrakiler bir kayar).
     * Hanede zaten "Ek ders" varsa (kullanıcı kendisi eklemiş) ya da Maaş türü yoksa dokunulmaz.
     */
    public function up(): void
    {
        foreach (DB::table('gelir_turleri')->where('ad', 'Maaş')->get(['hane_id', 'sira']) as $maas) {
            if (DB::table('gelir_turleri')->where('hane_id', $maas->hane_id)->where('ad', 'Ek ders')->exists()) {
                continue;
            }
            DB::table('gelir_turleri')->where('hane_id', $maas->hane_id)->where('sira', '>', $maas->sira)->increment('sira');
            DB::table('gelir_turleri')->insert([
                'hane_id' => $maas->hane_id, 'ad' => 'Ek ders', 'sira' => $maas->sira + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Yalnızca hiç kullanılmamış "Ek ders" türleri geri alınır (gelir / kaynak kaydı olan silinmez) */
    public function down(): void
    {
        $kullanilan = DB::table('gelirler')->whereNotNull('gelir_turu_id')->pluck('gelir_turu_id')
            ->merge(DB::table('gelir_kaynaklari')->whereNotNull('gelir_turu_id')->pluck('gelir_turu_id'))->unique()->all();
        DB::table('gelir_turleri')->where('ad', 'Ek ders')->whereNotIn('id', $kullanilan)->delete();
    }
};
