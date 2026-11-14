<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Yapay zekâ yedeğinin çalışma kaydı: kim, hangi ekstre, hangi model, sonuç ve süre. İçerik (metin, satır, tutar,
     * istek / yanıt) TUTULMAZ; yalnızca sayılar ve hata kodu.
     */
    public function up(): void
    {
        Schema::create('ekstre_ai_calismalari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('ekstre_id')->constrained('ekstreler')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('model', 60);
            $table->string('durum', 12); // basarili, hata
            $table->string('hata_kodu', 30)->nullable();
            $table->unsignedSmallInteger('sayfa_sayisi')->default(0);
            $table->unsignedSmallInteger('kabul_satir')->default(0);
            $table->unsignedSmallInteger('reddedilen_satir')->default(0);
            $table->unsignedInteger('sure_ms')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ekstre_ai_calismalari');
    }
};
