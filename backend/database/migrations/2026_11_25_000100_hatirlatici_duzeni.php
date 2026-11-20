<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kart borç hatırlatıcısı: hanenin seçtiği kart sırası (boş: tarihe göre sonda) ve ekstrenin "toplama eklendi"
     * işareti (seçilenlerin toplamı).
     */
    public function up(): void
    {
        Schema::table('kartlar', function (Blueprint $table) {
            $table->unsignedSmallInteger('hatirlatici_sira')->nullable()->after('aktif');
        });
        Schema::table('ekstreler', function (Blueprint $table) {
            $table->boolean('toplamda')->default(false)->after('odendi');
        });
    }

    public function down(): void
    {
        Schema::table('kartlar', function (Blueprint $table) {
            $table->dropColumn('hatirlatici_sira');
        });
        Schema::table('ekstreler', function (Blueprint $table) {
            $table->dropColumn('toplamda');
        });
    }
};
