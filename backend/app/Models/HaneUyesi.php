<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hanedeki kişi: gelirin, kartın sahibi. Girişi olan kullanıcıya bağlı (`user_id`) ya da yalnızca ad.
 * Kaydı olan üye silinmez, pasif olur.
 */
class HaneUyesi extends Model
{
    use HaneyeAit;

    protected $table = 'hane_uyeleri';

    protected $fillable = ['hane_id', 'user_id', 'ad', 'renk', 'sira', 'aktif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    /**
     * @return BelongsTo<Hane, $this>
     */
    public function hane(): BelongsTo
    {
        return $this->belongsTo(Hane::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'renk' => $this->renk,
            'user_id' => $this->user_id,
            'aktif' => $this->aktif,
            'sira' => $this->sira,
        ];
    }
}
