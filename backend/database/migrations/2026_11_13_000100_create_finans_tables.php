<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Varlık kataloğu ve fiyatlar ortaktır (bir kez çekilir); hesaplar, işlemler ve izleme listesi kullanıcıya aittir.
     * Bütün işlem tutarları TL'dir; USD fiyatlı varlıklar (ons) değerlemede USD/TRY ile çevrilir.
     */
    public function up(): void
    {
        Schema::create('varliklar', function (Blueprint $table) {
            $table->id();
            $table->string('tur', 10)->index(); // altin, doviz, hisse, fon, kripto
            $table->string('kod', 20);
            $table->string('ad', 120);
            $table->string('birim', 10); // gram, adet, pay, birim
            $table->char('para_birimi', 3)->default('TRY');
            $table->string('kaynak', 20); // truncgil, yahoo, binance, elle
            $table->string('kaynak_kodu', 40)->nullable();
            $table->boolean('gecikmeli')->default(false);
            $table->boolean('aktif')->default(true);
            $table->unsignedSmallInteger('sira')->default(0);
            $table->foreignId('ekleyen_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('gecmis_tarihi')->nullable(); // geçmiş fiyatlar en son ne zaman çekildi
            $table->timestamps();
            $table->unique(['tur', 'kod']);
        });

        Schema::create('fiyatlar', function (Blueprint $table) {
            $table->foreignId('varlik_id')->primary()->constrained('varliklar')->cascadeOnDelete();
            $table->decimal('fiyat', 20, 8); // değerleme fiyatı: alış (bid) varsa o, yoksa son fiyat
            $table->decimal('alis', 20, 8)->nullable();
            $table->decimal('satis', 20, 8)->nullable();
            $table->decimal('onceki_kapanis', 20, 8)->nullable();
            $table->decimal('degisim_yuzde', 10, 4)->nullable();
            $table->string('kaynak', 20);
            $table->boolean('elle')->default(false);
            $table->timestamp('zaman');
            $table->timestamps();
        });

        Schema::create('fiyat_gecmisi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('varlik_id')->constrained('varliklar')->cascadeOnDelete();
            $table->date('tarih');
            $table->decimal('kapanis', 20, 8);
            $table->unique(['varlik_id', 'tarih']);
        });

        Schema::create('kaynak_durumlari', function (Blueprint $table) {
            $table->string('kaynak', 20)->primary();
            $table->timestamp('son_deneme')->nullable();
            $table->timestamp('son_basari')->nullable();
            $table->text('son_hata')->nullable();
            $table->timestamp('son_hata_zamani')->nullable();
            $table->unsignedInteger('art_arda_hata')->default(0);
            $table->unsignedInteger('guncellenen')->default(0);
            $table->timestamps();
        });

        Schema::create('hesaplar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ad', 60);
            $table->string('tur', 20); // araci_kurum, banka, kasa, ev, kripto, diger
            $table->string('renk', 20)->nullable();
            $table->unsignedSmallInteger('sira')->default(0);
            $table->string('not', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'ad']);
        });

        Schema::create('islemler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hesap_id')->constrained('hesaplar')->cascadeOnDelete();
            $table->foreignId('varlik_id')->constrained('varliklar')->restrictOnDelete();
            $table->string('tur', 10); // alis, satis, mevcut
            $table->decimal('miktar', 24, 8);
            $table->decimal('birim_fiyat', 20, 8)->nullable(); // TL; boşsa maliyet / satış fiyatı bilinmiyor
            $table->decimal('komisyon', 16, 2)->default(0);
            $table->date('tarih');
            $table->string('not', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'hesap_id', 'varlik_id', 'tarih']);
        });

        Schema::create('izleme_listesi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('varlik_id')->constrained('varliklar')->cascadeOnDelete();
            $table->unsignedSmallInteger('sira')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'varlik_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('izleme_listesi');
        Schema::dropIfExists('islemler');
        Schema::dropIfExists('hesaplar');
        Schema::dropIfExists('kaynak_durumlari');
        Schema::dropIfExists('fiyat_gecmisi');
        Schema::dropIfExists('fiyatlar');
        Schema::dropIfExists('varliklar');
    }
};
