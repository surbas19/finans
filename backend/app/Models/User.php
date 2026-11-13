<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['ad', 'soyad', 'email', 'password', 'yonetici', 'aktif'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'yonetici' => 'boolean',
            'aktif' => 'boolean',
            'son_giris' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Hesap, $this>
     */
    public function hesaplar(): HasMany
    {
        return $this->hasMany(Hesap::class)->orderBy('sira')->orderBy('id');
    }

    /**
     * @return array{id: int, ad: string, soyad: string, ad_soyad: string, email: string, yonetici: bool}
     */
    public function oturumBilgisi(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'soyad' => $this->soyad,
            'ad_soyad' => $this->ad.' '.$this->soyad,
            'email' => $this->email,
            'yonetici' => $this->yonetici,
        ];
    }
}
