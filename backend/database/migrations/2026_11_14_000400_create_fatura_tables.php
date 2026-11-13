<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faturalar. Tür: sistem (elektrik, su, doğalgaz; hane boş) ya da hanenin eklediği (internet, aidat…).
     * Sağlayıcı: hanenin kurumu ("Enerjisa", "İSKİ") ve ekstrede görünen yazımları (desen). Fatura: hizmet dönemi
     * (`hizmet_donemi`, bilinmiyorsa boş), ödeme tarihi ve bağlı kart satırı ayrı; bir kart satırı tek faturaya bağlanır.
     */
    public function up(): void
    {
        Schema::create('fatura_turleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->nullable()->constrained('haneler')->cascadeOnDelete(); // boş: sistem türü
            $table->string('ad', 40);
            $table->string('ikon', 40)->nullable();
            $table->json('anahtar_kelimeler')->nullable(); // sade metin; zayıf kanıt (sağlayıcı tanımlı değilse)
            $table->unsignedSmallInteger('sira')->default(0);
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });

        Schema::create('fatura_saglayicilari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('fatura_turu_id')->constrained('fatura_turleri')->restrictOnDelete();
            $table->string('ad', 60);
            $table->boolean('otomatik_kayit')->default(false); // güvenilir eşleşmede onay beklemeden fatura
            $table->boolean('aktif')->default(true);
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });

        Schema::create('saglayici_desenleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('saglayici_id')->constrained('fatura_saglayicilari')->cascadeOnDelete();
            $table->string('desen', 60); // sade metin: ekstre açıklamasında geçen yazım
            $table->timestamps();
            $table->unique(['hane_id', 'desen']);
        });

        Schema::create('faturalar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('saglayici_id')->constrained('fatura_saglayicilari')->restrictOnDelete();
            $table->foreignId('fatura_turu_id')->constrained('fatura_turleri')->restrictOnDelete();
            $table->char('hizmet_donemi', 7)->nullable(); // hizmetin ait olduğu ay; boş = bilinmiyor
            $table->string('donem_kaynagi', 20)->nullable(); // aciklama (ekstre metninden) | kullanici
            $table->decimal('tutar', 15, 2);
            $table->date('son_odeme_tarihi')->nullable();
            $table->string('durum', 20); // bekliyor, odendi
            $table->date('odeme_tarihi')->nullable();
            $table->foreignId('kart_islemi_id')->nullable()->unique()->constrained('kart_islemleri')->nullOnDelete();
            $table->json('eslesme')->nullable(); // otomatik eşleşmenin güveni ve gerekçesi
            $table->string('kaynak', 20); // elle, ekstre
            $table->string('not', 500)->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hane_id', 'hizmet_donemi']);
            $table->index(['hane_id', 'durum']);
        });

        $zaman = now();
        DB::table('fatura_turleri')->insert([
            ['hane_id' => null, 'ad' => 'Elektrik', 'ikon' => 'pi pi-bolt', 'sira' => 1, 'created_at' => $zaman, 'updated_at' => $zaman,
                'anahtar_kelimeler' => json_encode(['ELEKTRIK', 'ENERJI', 'EDAS', 'ENERJISA', 'CK ENERJI', 'ENERJI PERAKENDE', 'ELEKTRIK DAGITIM'])],
            ['hane_id' => null, 'ad' => 'Su', 'ikon' => 'pi pi-filter', 'sira' => 2, 'created_at' => $zaman, 'updated_at' => $zaman,
                'anahtar_kelimeler' => json_encode(['SU IDARESI', 'SU VE KANALIZASYON', 'SU FATURA', 'ISKI', 'ASKI', 'IZSU', 'BUSKI', 'SASKI', 'KOSKI', 'MASKI', 'DESKI'])],
            ['hane_id' => null, 'ad' => 'Doğalgaz', 'ikon' => 'pi pi-sun', 'sira' => 3, 'created_at' => $zaman, 'updated_at' => $zaman,
                'anahtar_kelimeler' => json_encode(['DOGALGAZ', 'DOGAL GAZ', 'IGDAS', 'BASKENTGAZ', 'IZMIRGAZ', 'ENERYA', 'BURSAGAZ', 'GAZDAS', 'AKSA DOGALGAZ'])],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('faturalar');
        Schema::dropIfExists('saglayici_desenleri');
        Schema::dropIfExists('fatura_saglayicilari');
        Schema::dropIfExists('fatura_turleri');
    }
};
