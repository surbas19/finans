<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Silinen kayıtların arşivi (anlık görüntü): ne, kim yaptı / ne zaman, kim sildi / ne zaman. Hiçbir yolla silinmez;
     * yalnızca hane silinirse gider. `hane_id` boş: hanesi olmayan kullanıcının silmeleri (yalnız silen görür).
     */
    public function up(): void
    {
        Schema::create('silinen_kayitlar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->nullable()->constrained('haneler')->cascadeOnDelete();
            $table->string('tur', 30);
            $table->unsignedBigInteger('kayit_id');
            $table->string('baslik', 255);
            $table->decimal('tutar', 15, 2)->nullable();
            $table->json('icerik');
            $table->json('alt')->nullable();
            $table->string('neden', 255)->nullable();
            $table->unsignedBigInteger('olusturan_id')->nullable();
            $table->string('olusturan_ad', 160)->nullable();
            $table->timestamp('olusturuldu_at')->nullable();
            $table->unsignedBigInteger('silen_id')->nullable();
            $table->string('silen_ad', 160);
            $table->timestamp('silindi_at');
            $table->index(['hane_id', 'silindi_at']);
            $table->index(['hane_id', 'tur']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silinen_kayitlar');
    }
};
