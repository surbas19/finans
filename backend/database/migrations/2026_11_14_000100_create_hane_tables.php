<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hane: aynı haneye bağlı kullanıcılar (ör. eşler) hesapları, işlemleri ve aile kayıtlarını birlikte görür.
     * Hanesi olmayan kullanıcı yalnızca kendini görür (eski davranış); mevcut veri taşınmaz.
     * Üye: hanedeki kişi; girişi olan kullanıcıya bağlı ya da yalnızca ad (ör. çocuk).
     */
    public function up(): void
    {
        Schema::create('haneler', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 60);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('hane_id')->nullable()->after('aktif')->constrained('haneler')->nullOnDelete();
        });

        Schema::create('hane_uyeleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hane_id')->constrained('haneler')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('ad', 60);
            $table->string('renk', 20)->nullable();
            $table->unsignedSmallInteger('sira')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
            $table->unique(['hane_id', 'ad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hane_uyeleri');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hane_id');
        });
        Schema::dropIfExists('haneler');
    }
};
