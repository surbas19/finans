<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asgari ödeme oranı (%): ekstreden okunamazsa asgari ödeme = dönem borcu × oran. Varsayılan 40.
     */
    public function up(): void
    {
        Schema::table('kartlar', function (Blueprint $table) {
            $table->unsignedTinyInteger('asgari_oran')->default(40)->after('son_odeme_gunu');
        });
    }

    public function down(): void
    {
        Schema::table('kartlar', function (Blueprint $table) {
            $table->dropColumn('asgari_oran');
        });
    }
};
