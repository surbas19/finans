<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gelir türleri hanenin listesi olur (eskiden sabit enum). Her haneye eski 8 tür aynı adlarla yazılır, gelir ve
 * kaynakların türü bu satırlara bağlanır, `tur` sütunu kalkar. Geri alınca eski anahtarlar döner; hanenin eklediği
 * türler "diger" olur.
 */
return new class extends Migration
{
    /** Eski enum anahtarı => ad (bu migration'ın anlık kopyası; uygulamadaki liste değişse de bu kalır) */
    private const ESKI_TURLER = [
        'maas' => 'Maaş',
        'kira' => 'Kira geliri',
        'serbest' => 'Serbest çalışma',
        'ek_is' => 'Ek iş',
        'prim' => 'Prim / ikramiye',
        'faiz_temettu' => 'Faiz / temettü',
        'emekli' => 'Emekli maaşı',
        'diger' => 'Diğer',
    ];

    private const TABLOLAR = ['gelir_kaynaklari', 'gelirler'];

    public function up(): void
    {
        Schema::create('gelir_turleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('ad', 40);
            $table->unsignedSmallInteger('sira')->default(0);
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });
        foreach (self::TABLOLAR as $tablo) {
            Schema::table($tablo, function (Blueprint $table) {
                // Kullanılan tür silinmez
                $table->foreignId('gelir_turu_id')->nullable()->after('uye_id')->constrained('gelir_turleri')->restrictOnDelete();
            });
        }

        $zaman = now();
        foreach (DB::table('haneler')->orderBy('id')->pluck('id') as $haneId) {
            $sira = 0;
            $diger = null;
            foreach (self::ESKI_TURLER as $anahtar => $ad) {
                $id = DB::table('gelir_turleri')->insertGetId([
                    'hane_id' => $haneId, 'ad' => $ad, 'sira' => ++$sira, 'created_at' => $zaman, 'updated_at' => $zaman,
                ]);
                $diger = $anahtar === 'diger' ? $id : $diger;
                foreach (self::TABLOLAR as $tablo) {
                    DB::table($tablo)->where('hane_id', $haneId)->where('tur', $anahtar)->update(['gelir_turu_id' => $id]);
                }
            }
            // Tanınmayan eski anahtar (olmamalı): Diğer
            foreach (self::TABLOLAR as $tablo) {
                DB::table($tablo)->where('hane_id', $haneId)->whereNull('gelir_turu_id')->update(['gelir_turu_id' => $diger]);
            }
        }

        foreach (self::TABLOLAR as $tablo) {
            Schema::table($tablo, function (Blueprint $table) {
                $table->dropColumn('tur');
            });
            Schema::table($tablo, function (Blueprint $table) {
                $table->unsignedBigInteger('gelir_turu_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        $anahtarlar = array_flip(self::ESKI_TURLER);
        $turler = DB::table('gelir_turleri')->pluck('ad', 'id');
        foreach (self::TABLOLAR as $tablo) {
            Schema::table($tablo, function (Blueprint $table) {
                $table->string('tur', 20)->nullable()->after('uye_id');
            });
            foreach ($turler as $id => $ad) {
                DB::table($tablo)->where('gelir_turu_id', $id)->update(['tur' => $anahtarlar[$ad] ?? 'diger']);
            }
            DB::table($tablo)->whereNull('tur')->update(['tur' => 'diger']);
            Schema::table($tablo, function (Blueprint $table) {
                $table->string('tur', 20)->nullable(false)->change();
                $table->dropConstrainedForeignId('gelir_turu_id');
            });
        }
        Schema::dropIfExists('gelir_turleri');
    }
};
