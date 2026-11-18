<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kartsız giderde ödeme yeri (`ev` | `banka`; eski kayıtlarda null = belirtilmemiş, ev nakdine dokunmaz) ve ev nakdi
     * kasası: bakiye = eklenenler − çıkarılanlar − ödeme yeri "ev" olan giderler.
     */
    public function up(): void
    {
        Schema::table('duzenli_giderler', function (Blueprint $table) {
            $table->string('odeme_yeri', 10)->nullable()->after('tarih');
        });

        Schema::create('ev_nakdi_hareketleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('tur', 10); // ekleme | cikarma
            $table->decimal('tutar', 15, 2);
            $table->date('tarih');
            $table->string('not', 500)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('hane_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_nakdi_hareketleri');
        Schema::table('duzenli_giderler', function (Blueprint $table) {
            $table->dropColumn('odeme_yeri');
        });
    }
};
