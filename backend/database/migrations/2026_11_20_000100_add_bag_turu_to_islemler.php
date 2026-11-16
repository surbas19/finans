<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eşleşik kayıtların (`aktarim_kodu`) ne olduğu: `aktarim` (hesaplar arası aktarım) ya da `temettu` (hisse
     * satırındaki temettü + aynı hesaba nakit). Mevcut eşleşik kayıtların hepsi aktarımdır.
     */
    public function up(): void
    {
        Schema::table('islemler', function (Blueprint $table) {
            $table->string('bag_turu', 10)->nullable()->after('aktarim_kodu');
        });
        DB::table('islemler')->whereNotNull('aktarim_kodu')->update(['bag_turu' => 'aktarim']);
    }

    public function down(): void
    {
        Schema::table('islemler', function (Blueprint $table) {
            $table->dropColumn('bag_turu');
        });
    }
};
