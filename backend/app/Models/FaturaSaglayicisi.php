<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fatura kurumu ("Enerjisa", "İSKİ") ve ekstrede görünen yazımları (desenler). `otomatik_kayit` açıksa güvenilir
 * eşleşme onay beklemeden faturaya yazılır.
 */
class FaturaSaglayicisi extends Model
{
    use HaneyeAit;

    protected $table = 'fatura_saglayicilari';

    protected $fillable = ['hane_id', 'fatura_turu_id', 'ad', 'otomatik_kayit', 'aktif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['otomatik_kayit' => 'boolean', 'aktif' => 'boolean'];
    }

    /**
     * @return BelongsTo<FaturaTuru, $this>
     */
    public function tur(): BelongsTo
    {
        return $this->belongsTo(FaturaTuru::class, 'fatura_turu_id');
    }

    /**
     * @return HasMany<SaglayiciDeseni, $this>
     */
    public function desenler(): HasMany
    {
        return $this->hasMany(SaglayiciDeseni::class, 'saglayici_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'fatura_turu_id' => $this->fatura_turu_id,
            'otomatik_kayit' => $this->otomatik_kayit,
            'aktif' => $this->aktif,
            'desenler' => $this->relationLoaded('desenler')
                ? $this->desenler->map(fn (SaglayiciDeseni $d): array => ['id' => $d->id, 'desen' => $d->desen])->values()
                : [],
        ];
    }
}
