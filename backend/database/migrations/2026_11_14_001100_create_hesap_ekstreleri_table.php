<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banka hesabı ekstresi (kart değil: havale, kira, maaş, açılış / kapanış bakiyesi). Yalnızca yapay zekâ analizi ve
 * önizleme; Bütçe'ye aktarım sonraki iş. PDF analizden sonra silinir (dosya_yolu boşalır), maskeli JSON kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hesap_ekstreleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('durum', 20)->default('analiz'); // analiz, onizleme, hata
            $table->string('banka_adi', 60)->nullable();
            $table->date('donem_baslangic')->nullable();
            $table->date('donem_bitis')->nullable();
            $table->decimal('acilis_bakiyesi', 15, 2)->nullable();
            $table->decimal('kapanis_bakiyesi', 15, 2)->nullable();
            $table->json('denetim')->nullable(); // bakiye denetimi, sayfa denetimi, reddedilen satırlar
            $table->json('ai_cikti')->nullable(); // maskeli standart JSON
            $table->string('dosya_yolu')->nullable();
            $table->string('dosya_adi', 190)->nullable();
            $table->unsignedInteger('dosya_boyutu')->nullable();
            $table->unsignedSmallInteger('sayfa_sayisi')->nullable();
            $table->char('dosya_ozeti', 64);
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['hane_id', 'dosya_ozeti']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hesap_ekstreleri');
    }
};
