<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Önceden planlanan temettü: hesap + hisse, ödeme tarihi, hisse başı brüt TL ve stopaj %. Lot ödeme gününde hesaptaki
     * hisse adedidir (plana yazılmaz); yatacak net = lot × hisse başı × (1 − stopaj). Yatınca `TemettuKaydi` ile gerçek
     * temettü (hisse satırı + hesaba nakit) olur, plan `yatti` işaretlenir.
     */
    public function up(): void
    {
        Schema::create('temettu_planlari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hesap_id')->constrained('hesaplar')->cascadeOnDelete();
            $table->foreignId('varlik_id')->constrained('varliklar')->cascadeOnDelete();
            $table->date('tarih');
            $table->decimal('hisse_basi', 15, 6);
            $table->decimal('stopaj_yuzde', 5, 2)->default(10);
            $table->string('not', 255)->nullable();
            $table->string('durum', 10)->default('bekliyor'); // bekliyor | yatti
            $table->foreignId('islem_id')->nullable()->constrained('islemler')->nullOnDelete();
            $table->timestamp('yatti_at')->nullable();
            $table->timestamps();
            $table->index(['hesap_id', 'durum', 'tarih']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temettu_planlari');
    }
};
