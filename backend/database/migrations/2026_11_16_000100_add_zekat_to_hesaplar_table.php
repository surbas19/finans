<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hesap zekât hesabına dahil mi (varsayılan evet; ör. emanet ya da zekâta tabi olmayan hesap kapatılır). */
    public function up(): void
    {
        Schema::table('hesaplar', function (Blueprint $table) {
            $table->boolean('zekata_dahil')->default(true)->after('renk');
            $table->string('zekat_notu', 255)->nullable()->after('zekata_dahil');
        });
    }

    public function down(): void
    {
        Schema::table('hesaplar', function (Blueprint $table) {
            $table->dropColumn(['zekata_dahil', 'zekat_notu']);
        });
    }
};
