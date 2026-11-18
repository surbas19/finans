<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Borç / alacak defteri: kişiyle verilen ya da alınan para ve kısmi ödemeleri. Bütçe gider / gelirine, ev nakdine ve
     * zekâta karışmaz.
     */
    public function up(): void
    {
        Schema::create('borclar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('yon', 10); // borc (ben borçluyum) | alacak (bana borçlu)
            $table->string('kisi', 80);
            $table->decimal('tutar', 15, 2);
            $table->date('tarih');
            $table->date('vade')->nullable();
            $table->string('not', 500)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hane_id', 'yon']);
        });

        Schema::create('borc_odemeleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('borc_id')->constrained('borclar')->cascadeOnDelete();
            $table->decimal('tutar', 15, 2);
            $table->date('tarih');
            $table->string('not', 500)->nullable();
            $table->timestamps();
            $table->index('borc_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borc_odemeleri');
        Schema::dropIfExists('borclar');
    }
};
