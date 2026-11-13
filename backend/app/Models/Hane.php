<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aynı haneye bağlı kullanıcılar hesapları, işlemleri ve aile kayıtlarını (gelir, kart, ekstre, fatura) birlikte
 * görür ve düzenler. Erişim kuralı tek yerde: `User::haneKullaniciIdleri()`.
 */
class Hane extends Model
{
    protected $table = 'haneler';

    protected $fillable = ['ad'];

    /**
     * @return HasMany<User, $this>
     */
    public function kullanicilar(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<HaneUyesi, $this>
     */
    public function uyeler(): HasMany
    {
        return $this->hasMany(HaneUyesi::class)->orderBy('sira')->orderBy('id');
    }
}
