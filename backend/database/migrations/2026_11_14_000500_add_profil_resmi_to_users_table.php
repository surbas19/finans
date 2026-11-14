<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil resmi: özel diskteki dosyanın yolu (`profil/{id}-{rastgele}.{uzantı}`); açık adresi yok.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profil_resmi', 100)->nullable()->after('yonetici');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profil_resmi');
        });
    }
};
