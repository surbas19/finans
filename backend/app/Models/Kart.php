<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kredi kartı: yalnızca kullanıcının verdiği ad, banka, sahibi, kesim ve son ödeme günü (numara tutulmaz).
 */
class Kart extends Model
{
    use HaneyeAit;

    protected $table = 'kartlar';

    protected $fillable = ['hane_id', 'banka_id', 'sahip_uye_id', 'ad', 'kesim_gunu', 'son_odeme_gunu', 'asgari_oran', 'renk', 'aktif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    /**
     * @return BelongsTo<Banka, $this>
     */
    public function banka(): BelongsTo
    {
        return $this->belongsTo(Banka::class);
    }

    /**
     * @return BelongsTo<HaneUyesi, $this>
     */
    public function sahip(): BelongsTo
    {
        return $this->belongsTo(HaneUyesi::class, 'sahip_uye_id');
    }

    /**
     * @return HasMany<Ekstre, $this>
     */
    public function ekstreler(): HasMany
    {
        return $this->hasMany(Ekstre::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'banka_id' => $this->banka_id,
            'sahip_uye_id' => $this->sahip_uye_id,
            'kesim_gunu' => $this->kesim_gunu,
            'son_odeme_gunu' => $this->son_odeme_gunu,
            'asgari_oran' => (int) ($this->asgari_oran ?? 40),
            'renk' => $this->renk,
            'aktif' => $this->aktif,
        ];
    }
}
