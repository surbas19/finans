<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banka ödeme yerli kartsız gider: düşülen portföy hesabı ve ona yazılan Nakit (TL) satış işlemi. Eski banka
     * kayıtlarında ikisi de boş kalır (hiçbir bakiyeden düşülmemiştir).
     */
    public function up(): void
    {
        Schema::table('duzenli_giderler', function (Blueprint $table) {
            $table->foreignId('hesap_id')->nullable()->after('odeme_yeri')->constrained('hesaplar')->nullOnDelete();
            $table->foreignId('islem_id')->nullable()->after('hesap_id')->constrained('islemler')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('duzenli_giderler', function (Blueprint $table) {
            $table->dropConstrainedForeignId('islem_id');
            $table->dropConstrainedForeignId('hesap_id');
        });
    }
};
