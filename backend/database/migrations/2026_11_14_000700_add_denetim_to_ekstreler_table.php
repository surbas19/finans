<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ekstre mutabakatı ve banka profili: PDF okumasının sonucu (`EkstreMutabakati`); kalan taksit tutarı Yapı Kredi gibi
     * "kalan / N" yazan bankalarda satırdan okunur ve ekstrede toplanır (gelecek dönem yükümlülüğü).
     */
    public function up(): void
    {
        Schema::table('ekstreler', function (Blueprint $table) {
            $table->string('banka_profili', 20)->nullable()->after('okunamayan');
            $table->json('denetim')->nullable()->after('banka_profili');
            $table->decimal('kalan_taksit', 15, 2)->nullable()->after('denetim');
        });
        Schema::table('kart_islemleri', function (Blueprint $table) {
            $table->decimal('taksit_kalan', 15, 2)->nullable()->after('taksit_toplam');
        });
    }

    public function down(): void
    {
        Schema::table('kart_islemleri', function (Blueprint $table) {
            $table->dropColumn('taksit_kalan');
        });
        Schema::table('ekstreler', function (Blueprint $table) {
            $table->dropColumn(['banka_profili', 'denetim', 'kalan_taksit']);
        });
    }
};
