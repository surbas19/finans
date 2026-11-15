<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Altın / döviz gününde sıranın bizde olduğu ay (toplanan altın bize verilir): o ay alım beklenmez.
     */
    public function up(): void
    {
        Schema::table('tasarruf_gunleri', function (Blueprint $table) {
            $table->char('bizde_ay', 7)->nullable()->after('ay_sayisi'); // YYYY-AA
        });
    }

    public function down(): void
    {
        Schema::table('tasarruf_gunleri', function (Blueprint $table) {
            $table->dropColumn('bizde_ay');
        });
    }
};
