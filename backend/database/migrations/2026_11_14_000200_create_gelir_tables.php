<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gelirler. Kaynak: düzenli (maaş, kira…) ya da düzensiz gelirin tanımı; beklenen gelir kayıttan değil kaynaktan
     * hesaplanır ve gerçekleşen toplama katılmaz. Kayıt: gerçekleşen gelir; `donem` ait olduğu ay (YYYY-AA), `tarih`
     * elde edildiği gün. Tutar kendi para biriminde, `tl_tutar` raporlarda kullanılan TL karşılığı.
     */
    public function up(): void
    {
        Schema::create('gelir_kaynaklari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('uye_id')->constrained('hane_uyeleri')->restrictOnDelete();
            $table->string('tur', 20);
            $table->string('ad', 80); // kaynak: "ABC A.Ş.", "Kadıköy daire"
            $table->string('duzen', 20); // duzenli, duzensiz, tek_seferlik
            $table->string('siklik', 20)->nullable(); // düzenlide: aylik, iki_aylik, uc_aylik, alti_aylik, yillik
            $table->decimal('beklenen_tutar', 15, 2)->nullable();
            $table->char('para_birimi', 3)->default('TRY');
            $table->unsignedTinyInteger('gun')->nullable(); // beklenen gün
            $table->date('baslangic')->nullable();
            $table->date('bitis')->nullable();
            $table->boolean('aktif')->default(true);
            $table->string('not', 500)->nullable();
            $table->timestamps();
            $table->index(['hane_id', 'uye_id']);
        });

        Schema::create('gelirler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('uye_id')->constrained('hane_uyeleri')->restrictOnDelete();
            $table->foreignId('gelir_kaynagi_id')->nullable()->constrained('gelir_kaynaklari')->nullOnDelete();
            $table->string('tur', 20);
            $table->string('duzen', 20);
            $table->string('aciklama', 120)->nullable();
            $table->decimal('tutar', 15, 2);
            $table->char('para_birimi', 3)->default('TRY');
            $table->decimal('tl_tutar', 15, 2);
            $table->decimal('kur', 18, 6)->nullable();
            $table->date('tarih'); // elde edildiği / tahakkuk ettiği gün
            $table->char('donem', 7); // ait olduğu ay: 2026-01
            $table->string('not', 500)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hane_id', 'donem']);
            // Aynı kaynağın bir dönemi bir kez girilir (kaynaksız gelirde sınır yok)
            $table->unique(['gelir_kaynagi_id', 'donem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gelirler');
        Schema::dropIfExists('gelir_kaynaklari');
    }
};
