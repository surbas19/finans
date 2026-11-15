<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasarruf: bütçeden ayrılan ve portföy hesabına (ev, banka kasası, yatırım…) alış olarak yazılan tutar. Portföy
     * `islemler` kaydı gerçek kayıttır; bu tablo yalnızca bütçedeki dönem bağıdır. Portföy işlemi silinirse satır da gider.
     */
    public function up(): void
    {
        Schema::create('tasarruflar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('uye_id')->nullable()->constrained('hane_uyeleri')->nullOnDelete();
            $table->foreignId('hesap_id')->constrained('hesaplar')->cascadeOnDelete();
            $table->foreignId('islem_id')->constrained('islemler')->cascadeOnDelete();
            $table->date('tarih');
            $table->char('donem', 7); // tarihin ayı
            $table->decimal('tutar_tl', 15, 2); // miktar × birim fiyat + komisyon
            $table->string('not', 255)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hane_id', 'donem']);
            $table->unique('islem_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasarruflar');
    }
};
