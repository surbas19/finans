<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Yatırım satırı (hesap + varlık) genel toplama ve zekât hesabına dahil mi. Yalnızca dahil OLMAYAN çiftler
     * satır olarak tutulur; satır yoksa dahildir.
     */
    public function up(): void
    {
        Schema::create('pozisyon_ayarlari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hesap_id')->constrained('hesaplar')->cascadeOnDelete();
            $table->foreignId('varlik_id')->constrained('varliklar')->cascadeOnDelete();
            $table->boolean('dahil')->default(false);
            $table->timestamps();
            $table->unique(['hesap_id', 'varlik_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pozisyon_ayarlari');
    }
};
