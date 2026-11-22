<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Önceden planlanan temettü (bkz. `App\Services\Portfoy\TemettuPlanlari`). Hesabı görebilen herkes görür ve düzenler.
 */
class TemettuPlani extends Model
{
    public const BEKLIYOR = 'bekliyor';

    public const YATTI = 'yatti';

    protected $table = 'temettu_planlari';

    protected $fillable = ['user_id', 'hesap_id', 'varlik_id', 'tarih', 'hisse_basi', 'stopaj_yuzde', 'not', 'durum', 'islem_id', 'yatti_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tarih' => 'date', 'hisse_basi' => 'float', 'stopaj_yuzde' => 'float', 'yatti_at' => 'datetime'];
    }

    /**
     * @param  Builder<TemettuPlani>  $sorgu
     */
    public function scopeErisilebilir(Builder $sorgu, User $user): void
    {
        $sorgu->whereIn('temettu_planlari.hesap_id', Hesap::erisilebilir($user)->select('hesaplar.id'));
    }

    /**
     * @return BelongsTo<Hesap, $this>
     */
    public function hesap(): BelongsTo
    {
        return $this->belongsTo(Hesap::class);
    }

    /**
     * @return BelongsTo<Varlik, $this>
     */
    public function varlik(): BelongsTo
    {
        return $this->belongsTo(Varlik::class);
    }
}
