<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Yapay zekâ ile ekstre analizi arka planda yürür: çalışma kaydına aşama, zamanlar, OpenAI yanıt kimliği, yoklama
 * zamanı, kilit (geç gelen sonuç yazmasın), çalıştıran (arka plan / cron), onay sürümü ve zamanı. Kayıt kart ekstresine
 * ya da banka hesabı ekstresine bağlıdır (biri dolu). Ekstreye maskeli standart JSON (JSON görünümü). İçerik yine
 * çalışma kaydına yazılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ekstreler', function (Blueprint $table) {
            $table->json('ai_cikti')->nullable()->after('denetim');
        });
        Schema::table('ekstre_ai_calismalari', function (Blueprint $table) {
            $table->dropForeign(['ekstre_id']);
        });
        Schema::table('ekstre_ai_calismalari', function (Blueprint $table) {
            $table->unsignedBigInteger('ekstre_id')->nullable()->change();
            $table->foreign('ekstre_id')->references('id')->on('ekstreler')->cascadeOnDelete();
            $table->foreignId('hesap_ekstresi_id')->nullable()->after('ekstre_id')->constrained('hesap_ekstreleri')->cascadeOnDelete();
            $table->string('asama', 20)->nullable()->after('durum'); // sirada, gonderildi, okunuyor, dogrulaniyor, tamam, hata
            $table->string('calistirici', 12)->nullable()->after('asama'); // arka_plan, cron
            $table->string('openai_yanit_id', 100)->nullable();
            $table->string('kilit', 40)->nullable();
            $table->string('onay_surumu', 20)->nullable();
            $table->timestamp('onay_at')->nullable();
            $table->timestamp('basladi_at')->nullable();
            $table->timestamp('son_kontrol_at')->nullable();
            $table->timestamp('bitti_at')->nullable();
            $table->unsignedInteger('girdi_token')->nullable();
            $table->unsignedInteger('cikti_token')->nullable();
            $table->index(['durum', 'created_at']);
        });
    }

    public function down(): void
    {
        // Banka hesabı çalışmaları (ekstre_id boş) eski şemaya sığmaz
        DB::table('ekstre_ai_calismalari')->whereNull('ekstre_id')->delete();
        Schema::table('ekstre_ai_calismalari', function (Blueprint $table) {
            $table->dropIndex(['durum', 'created_at']);
            $table->dropConstrainedForeignId('hesap_ekstresi_id');
            $table->dropColumn(['asama', 'calistirici', 'openai_yanit_id', 'kilit', 'onay_surumu', 'onay_at', 'basladi_at', 'son_kontrol_at', 'bitti_at', 'girdi_token', 'cikti_token']);
            $table->dropForeign(['ekstre_id']);
        });
        Schema::table('ekstre_ai_calismalari', function (Blueprint $table) {
            $table->unsignedBigInteger('ekstre_id')->nullable(false)->change();
            $table->foreign('ekstre_id')->references('id')->on('ekstreler')->cascadeOnDelete();
        });
        Schema::table('ekstreler', function (Blueprint $table) {
            $table->dropColumn('ai_cikti');
        });
    }
};
