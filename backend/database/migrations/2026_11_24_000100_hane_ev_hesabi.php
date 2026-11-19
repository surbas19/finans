<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ev nakdi = seçilen portföy hesabının Nakit (TL) bakiyesi (ör. "Ev" hesabı). Boşsa ayrı ev nakdi kasası sürer.
     */
    public function up(): void
    {
        Schema::table('haneler', function (Blueprint $table) {
            $table->foreignId('ev_hesap_id')->nullable()->after('ad')->constrained('hesaplar')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('haneler', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ev_hesap_id');
        });
    }
};
