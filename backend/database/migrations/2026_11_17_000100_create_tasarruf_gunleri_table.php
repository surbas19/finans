<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Altın / döviz "günü": her ay belirli bir varlıktan sabit miktar alınır. Gün yalnızca şablondur; her ayın alımı
     * `tasarruflar` kaydıdır (gun_id ile bağlanır), kime verildiği o kaydın notunda durur.
     */
    public function up(): void
    {
        Schema::create('tasarruf_gunleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('hesap_id')->constrained('hesaplar')->cascadeOnDelete();
            $table->foreignId('varlik_id')->constrained('varliklar');
            $table->string('ad', 80);
            $table->decimal('miktar', 20, 8); // her ay alınacak miktar (gram, USD…)
            $table->unsignedTinyInteger('ay_gunu'); // 1–28
            $table->date('baslangic'); // ilk alım ayı
            $table->unsignedSmallInteger('ay_sayisi')->nullable(); // boşsa süresiz
            $table->boolean('aktif')->default(true);
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('hane_id');
        });

        Schema::table('tasarruflar', function (Blueprint $table) {
            $table->foreignId('gun_id')->nullable()->after('islem_id')->constrained('tasarruf_gunleri')->nullOnDelete();
            $table->index(['gun_id', 'donem']);
        });
    }

    public function down(): void
    {
        Schema::table('tasarruflar', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gun_id');
        });
        Schema::dropIfExists('tasarruf_gunleri');
    }
};
