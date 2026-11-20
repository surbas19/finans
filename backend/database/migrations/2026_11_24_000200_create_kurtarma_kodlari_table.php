<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Yönetici kurtarma kodları: şifre unutulduğunda e-posta olmadan sıfırlamak için tek kullanımlık kodlar. Düz kod
     * saklanmaz; yalnızca `APP_KEY` ile alınmış HMAC-SHA256 özeti tutulur.
     */
    public function up(): void
    {
        Schema::create('kurtarma_kodlari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ozet', 64);
            $table->timestamp('kullanildi_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('user_id');
            $table->unique('ozet');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('kurtarma_kullanildi_at')->nullable()->after('son_giris');
        });
        // Şifre sıfırlama bağlantısı jetonları (Laravel parola aracısı); bu projede daha önce yaratılmamıştı
        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kurtarma_kullanildi_at');
        });
        Schema::dropIfExists('kurtarma_kodlari');
        Schema::dropIfExists('password_reset_tokens');
    }
};
