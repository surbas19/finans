<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kullanıcılar: kayıt kapalı, kullanıcıyı yönetici ekler. TC kimlik / banka hesap numarası tutulmaz.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 60);
            $table->string('soyad', 60);
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('yonetici')->default(false);
            $table->boolean('aktif')->default(true);
            $table->rememberToken();
            $table->timestamp('son_giris')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
