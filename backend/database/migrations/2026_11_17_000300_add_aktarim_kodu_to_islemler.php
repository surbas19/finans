<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hesaplar arası aktarım: kaynakta satış, hedefte alış kaydı aynı `aktarim_kodu` ile eşlenir.
     */
    public function up(): void
    {
        Schema::table('islemler', function (Blueprint $table) {
            $table->uuid('aktarim_kodu')->nullable()->after('not');
            $table->index('aktarim_kodu');
        });
    }

    public function down(): void
    {
        Schema::table('islemler', function (Blueprint $table) {
            $table->dropIndex(['aktarim_kodu']);
            $table->dropColumn('aktarim_kodu');
        });
    }
};
