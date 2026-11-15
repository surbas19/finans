<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Düzenli giderler (eğitim, bakıcı, aidat…). Kaynak: ödemenin tanımı; beklenen gider kayıttan değil kaynaktan
     * hesaplanır ve gerçekleşen toplama katılmaz. Kayıt: gerçekleşen kartsız gider; `donem` ait olduğu ay (YYYY-AA).
     * Kartla ödenen gider kart ekstresine girer, burada tekrar girilmez.
     */
    public function up(): void
    {
        Schema::create('gider_kaynaklari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('uye_id')->nullable()->constrained('hane_uyeleri')->nullOnDelete();
            $table->foreignId('kategori_id')->nullable()->constrained('harcama_kategorileri')->nullOnDelete();
            $table->string('ad', 80); // "Okul taksiti", "Bakıcı"
            $table->string('siklik', 20)->default('aylik'); // aylik, iki_aylik, uc_aylik, alti_aylik, yillik
            $table->decimal('beklenen_tutar', 15, 2)->nullable();
            $table->unsignedTinyInteger('gun')->nullable();
            $table->date('baslangic')->nullable();
            $table->date('bitis')->nullable();
            $table->boolean('aktif')->default(true);
            $table->string('not', 500)->nullable();
            $table->timestamps();
            $table->index('hane_id');
        });

        Schema::create('duzenli_giderler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('gider_kaynagi_id')->nullable()->constrained('gider_kaynaklari')->nullOnDelete();
            $table->foreignId('kategori_id')->nullable()->constrained('harcama_kategorileri')->nullOnDelete();
            $table->string('aciklama', 120);
            $table->decimal('tutar', 15, 2);
            $table->date('tarih');
            $table->char('donem', 7);
            $table->string('not', 500)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hane_id', 'donem']);
            // Aynı kaynağın bir dönemi bir kez girilir (kaynaksız giderde sınır yok)
            $table->unique(['gider_kaynagi_id', 'donem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duzenli_giderler');
        Schema::dropIfExists('gider_kaynaklari');
    }
};
