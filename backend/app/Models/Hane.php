<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aynı haneye bağlı kullanıcılar hesapları, işlemleri ve aile kayıtlarını (gelir, kart, ekstre, fatura) birlikte
 * görür ve düzenler. Erişim kuralı tek yerde: `User::haneKullaniciIdleri()`.
 */
class Hane extends Model
{
    protected $table = 'haneler';

    /** `ev_hesap_id`: ev nakdi olarak kullanılan portföy hesabı (boşsa ayrı ev nakdi kasası) */
    protected $fillable = ['ad', 'ev_hesap_id'];

    /**
     * @return BelongsTo<Hesap, $this>
     */
    public function evHesabi(): BelongsTo
    {
        return $this->belongsTo(Hesap::class, 'ev_hesap_id');
    }

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
