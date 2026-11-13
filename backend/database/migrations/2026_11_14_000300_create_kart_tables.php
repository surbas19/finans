<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bankalar, kredi kartları, ekstreler ve kart işlemleri; harcama kategorileri.
     * Kart numarası / son 4 hane / CVV tutulmaz: kart yalnızca kullanıcının verdiği adla tutulur.
     * Tarihler ayrı kavramlar: işlem tarihi (harcama günü), kesim tarihi (ekstrenin kapandığı gün), son ödeme tarihi
     * (kart borcunun ödeneceği gün). `rapor_donemi`: harcamanın aylık özette sayıldığı ay (KartIslemKurallari).
     */
    public function up(): void
    {
        Schema::create('bankalar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('ad', 60);
            $table->string('renk', 20)->nullable();
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });

        Schema::create('kartlar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('banka_id')->constrained('bankalar')->restrictOnDelete();
            $table->foreignId('sahip_uye_id')->constrained('hane_uyeleri')->restrictOnDelete();
            $table->string('ad', 60);
            $table->unsignedTinyInteger('kesim_gunu')->nullable();
            $table->unsignedTinyInteger('son_odeme_gunu')->nullable();
            $table->string('renk', 20)->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });

        Schema::create('harcama_kategorileri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->string('ad', 40);
            $table->string('ikon', 40)->nullable();
            $table->string('renk', 20)->nullable();
            $table->unsignedSmallInteger('sira')->default(0);
            $table->boolean('fatura')->default(false); // fatura ödemelerinin kategorisi
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });

        Schema::create('kategori_kurallari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('kategori_id')->constrained('harcama_kategorileri')->cascadeOnDelete();
            $table->string('desen', 60); // sade metin (Metin::sade): açıklamada geçerse bu kategori
            $table->timestamps();
            $table->unique(['hane_id', 'desen']);
        });

        Schema::create('ekstreler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('kart_id')->constrained('kartlar')->restrictOnDelete();
            $table->string('durum', 20); // taslak, onaylandi
            $table->date('donem_baslangic')->nullable();
            $table->date('kesim_tarihi')->nullable();
            $table->date('son_odeme_tarihi')->nullable();
            $table->decimal('donem_borcu', 15, 2)->nullable();
            $table->decimal('asgari_odeme', 15, 2)->nullable();
            $table->json('alan_kaynaklari')->nullable(); // alan => pdf | kullanici
            $table->json('okunamayan')->nullable(); // PDF'de bulunamayan alanlar
            $table->boolean('odendi')->default(false);
            // Onaylı ekstrede kesim tarihi: aynı kartın aynı dönemi iki kez onaylanamaz (veritabanı düzeyinde)
            $table->date('onayli_kesim_tarihi')->nullable();
            $table->string('dosya_yolu')->nullable(); // özel disk, açık adres yok
            $table->string('dosya_adi', 190)->nullable();
            $table->unsignedInteger('dosya_boyutu')->nullable();
            $table->unsignedSmallInteger('sayfa_sayisi')->nullable();
            $table->char('dosya_ozeti', 64)->nullable(); // SHA-256: aynı dosya tekrar yüklenmez
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('onaylayan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('onaylandi_at')->nullable();
            $table->timestamps();
            $table->unique(['hane_id', 'dosya_ozeti']);
            $table->unique(['kart_id', 'onayli_kesim_tarihi']);
        });

        Schema::create('kart_islemleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('kart_id')->constrained('kartlar')->restrictOnDelete();
            $table->foreignId('ekstre_id')->nullable()->constrained('ekstreler')->cascadeOnDelete();
            $table->unsignedInteger('sira')->default(0); // ekstredeki satır sırası
            $table->string('durum', 20); // taslak, onayli, atlandi
            $table->date('islem_tarihi')->nullable(); // taslakta okunamamış olabilir
            $table->string('aciklama', 255);
            $table->decimal('tutar', 15, 2)->nullable(); // her zaman pozitif; yönü türden
            $table->string('tur', 20); // harcama, iade, odeme, transfer, ucret, nakit_avans
            $table->unsignedTinyInteger('taksit_no')->nullable();
            $table->unsignedTinyInteger('taksit_sayisi')->nullable();
            $table->decimal('taksit_toplam', 15, 2)->nullable(); // taksitli alışın toplamı (ekstrede yazıyorsa)
            $table->foreignId('iade_islem_id')->nullable()->constrained('kart_islemleri')->nullOnDelete();
            $table->foreignId('kategori_id')->nullable()->constrained('harcama_kategorileri')->nullOnDelete();
            $table->char('rapor_donemi', 7)->nullable(); // aylık özette sayıldığı ay (onaylıda dolu)
            $table->char('parmak_izi', 64)->nullable(); // yalnızca onaylıda: aynı işlem iki kez onaylanamaz
            $table->foreignId('tekrar_islem_id')->nullable()->constrained('kart_islemleri')->nullOnDelete(); // taslakta: aynısı onaylı mı
            $table->text('ham_metin')->nullable(); // PDF'deki satır (karşılaştırma için)
            $table->unsignedSmallInteger('sayfa')->nullable();
            $table->json('okunamayan')->nullable();
            $table->json('fatura_onerisi')->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kart_id', 'parmak_izi']);
            $table->index(['hane_id', 'durum', 'rapor_donemi']);
            $table->index(['ekstre_id', 'sira']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kart_islemleri');
        Schema::dropIfExists('ekstreler');
        Schema::dropIfExists('kategori_kurallari');
        Schema::dropIfExists('harcama_kategorileri');
        Schema::dropIfExists('kartlar');
        Schema::dropIfExists('bankalar');
    }
};
