<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kapanmış ayın değişmez anlık görüntüsü (Defter). Güncellenmez, silinmez; hane silinirse gider.
        Schema::create('defter_aylari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('donem', 7);
            $table->timestamp('muhurlendi_at');
            $table->longText('icerik');
            $table->unique(['hane_id', 'donem']);
        });

        // Ay kapandıktan sonra o aya ait kayıtlarda yapılan ekleme / düzeltme / silme. Ay toplamlarına katılmaz.
        Schema::create('defter_degisiklikleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('donem', 7);
            $table->string('tip', 12); // eklendi | duzeltildi | silindi
            $table->string('tur', 30); // gelir | gider | tasarruf | borc | borc_odemesi | portfoy_islemi
            $table->unsignedBigInteger('kayit_id');
            $table->string('baslik', 255);
            $table->date('kayit_tarihi')->nullable();
            $table->decimal('eski_tutar', 15, 2)->nullable();
            $table->decimal('yeni_tutar', 15, 2)->nullable();
            $table->string('not', 255)->nullable();
            $table->unsignedBigInteger('yapan_id')->nullable();
            $table->string('yapan_ad', 160);
            $table->timestamp('yapildi_at');
            $table->index(['hane_id', 'donem', 'yapildi_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defter_degisiklikleri');
        Schema::dropIfExists('defter_aylari');
    }
};
