<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zekât: hane başına ayar, yıl bazlı değişmez anlık görüntüler (kalemler ve borçlar kopya), elle borç, verilen zekât.
     * Para decimal(15,2) (hesapta kuruş); hesap ve varlık adları kopyalanır, sonradan silme geçmişi bozmaz.
     */
    public function up(): void
    {
        Schema::create('zekat_ayarlari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->unique()->constrained('haneler')->cascadeOnDelete();
            $table->string('nisab_olcutu', 10)->default('altin'); // altin | gumus
            $table->decimal('nisab_altin_gram', 10, 3)->default(80.180);
            $table->decimal('nisab_gumus_gram', 10, 3)->default(561.000);
            $table->unsignedSmallInteger('oran_binde')->default(25); // binde 25 = %2,5
            $table->json('tur_kurallari'); // tür => {dahil, yuzde}
            $table->boolean('borc_dus')->default(false);
            $table->json('borc_kaynaklari'); // {kart, fatura, elle}
            $table->boolean('yalniz_gunde_nisab')->default(true);
            $table->string('zekat_gunu_turu', 10)->default('hicri'); // hicri | miladi
            $table->unsignedTinyInteger('hicri_ay')->default(9);
            $table->unsignedTinyInteger('hicri_gun')->default(1);
            $table->unsignedTinyInteger('miladi_ay')->default(1);
            $table->unsignedTinyInteger('miladi_gun')->default(1);
            $table->date('sonraki_zekat_gunu')->nullable();
            $table->unsignedSmallInteger('hatirlatma_gun')->default(30);
            $table->timestamps();
        });

        Schema::create('zekat_yillari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->date('zekat_gunu');
            $table->string('durum', 12)->default('taslak'); // taslak | kesinlesti
            $table->json('ayar_kopyasi');
            $table->decimal('brut', 15, 2);
            $table->decimal('borc', 15, 2)->default(0);
            $table->decimal('net', 15, 2);
            $table->decimal('nisab', 15, 2);
            $table->boolean('nisab_ustu');
            $table->decimal('zekat', 15, 2);
            $table->decimal('usd_kuru', 12, 4)->nullable();
            $table->decimal('nisab_birim_fiyat', 15, 4);
            $table->boolean('eksik_veri')->default(false);
            $table->json('eksikler')->nullable();
            $table->dateTime('hesaplandi_at');
            $table->dateTime('kesinlesti_at')->nullable();
            $table->string('not', 500)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['hane_id', 'zekat_gunu']);
        });

        Schema::create('zekat_yili_kalemleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zekat_yili_id')->constrained('zekat_yillari')->cascadeOnDelete();
            $table->foreignId('hesap_id')->nullable()->constrained('hesaplar')->nullOnDelete();
            $table->string('hesap_ad', 80);
            $table->string('varlik_kod', 40);
            $table->string('varlik_ad', 120);
            $table->string('varlik_tur', 20);
            $table->decimal('miktar', 24, 8);
            $table->decimal('birim_fiyat_tl', 20, 4)->nullable();
            $table->decimal('deger', 15, 2)->nullable(); // fiyatı yoksa null (sıfır sayılmaz)
            $table->boolean('dahil_edilen');
            $table->unsignedTinyInteger('kapsam_yuzde')->default(100);
            $table->decimal('hesaba_giren', 15, 2)->default(0);
            $table->string('dislanma_nedeni', 120)->nullable();
        });

        Schema::create('zekat_yili_borclari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zekat_yili_id')->constrained('zekat_yillari')->cascadeOnDelete();
            $table->string('kaynak', 10); // kart | fatura | elle
            $table->string('ad', 160);
            $table->decimal('tutar', 15, 2);
        });

        Schema::create('zekat_borclari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('ad', 120);
            $table->decimal('tutar', 15, 2);
            $table->date('vade_tarihi')->nullable();
            $table->string('not', 255)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('zekat_odemeleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('zekat_yili_id')->nullable()->constrained('zekat_yillari')->nullOnDelete();
            $table->foreignId('uye_id')->nullable()->constrained('hane_uyeleri')->nullOnDelete();
            $table->date('tarih');
            $table->decimal('tutar', 15, 2);
            $table->string('alici', 120)->nullable(); // serbest ad; IBAN / hesap numarası tutulmaz
            $table->string('not', 255)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hane_id', 'tarih']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zekat_odemeleri');
        Schema::dropIfExists('zekat_borclari');
        Schema::dropIfExists('zekat_yili_borclari');
        Schema::dropIfExists('zekat_yili_kalemleri');
        Schema::dropIfExists('zekat_yillari');
        Schema::dropIfExists('zekat_ayarlari');
    }
};
