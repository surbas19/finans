<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - Başkasına verilen gün alımı hiçbir hesaba yazılmaz: tasarruf satırı portföy işlemi (`islem_id`) ve hesap olmadan
     *   durabilir; ne alındığı kendi `varlik_id` / `miktar` alanlarından okunur.
     * - Portföy işlemi tek tek genel toplama / zekâta dahil olabilir (`islemler.dahil`).
     */
    public function up(): void
    {
        Schema::table('tasarruflar', function (Blueprint $table) {
            $table->unsignedBigInteger('islem_id')->nullable()->change();
            $table->unsignedBigInteger('hesap_id')->nullable()->change();
            $table->foreignId('varlik_id')->nullable()->after('hesap_id')->constrained('varliklar')->nullOnDelete();
            $table->decimal('miktar', 24, 8)->nullable()->after('varlik_id');
        });
        Schema::table('islemler', function (Blueprint $table) {
            $table->boolean('dahil')->default(true)->after('not');
        });
    }

    public function down(): void
    {
        Schema::table('islemler', function (Blueprint $table) {
            $table->dropColumn('dahil');
        });
        Schema::table('tasarruflar', function (Blueprint $table) {
            $table->dropConstrainedForeignId('varlik_id');
            $table->dropColumn('miktar');
        });
    }
};
