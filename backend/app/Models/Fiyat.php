<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fiyat extends Model
{
    protected $table = 'fiyatlar';

    protected $primaryKey = 'varlik_id';

    public $incrementing = false;

    protected $fillable = ['varlik_id', 'fiyat', 'alis', 'satis', 'onceki_kapanis', 'degisim_yuzde', 'kaynak', 'elle', 'zaman'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiyat' => 'float',
            'alis' => 'float',
            'satis' => 'float',
            'onceki_kapanis' => 'float',
            'degisim_yuzde' => 'float',
            'elle' => 'boolean',
            'zaman' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Varlik, $this>
     */
    public function varlik(): BelongsTo
    {
        return $this->belongsTo(Varlik::class);
    }

    /**
     * Önceki kapanış; kaynak vermediyse değişim yüzdesinden geri hesaplanır.
     */
    public function dunkuFiyat(): ?float
    {
        if ($this->onceki_kapanis) {
            return $this->onceki_kapanis;
        }
        if ($this->degisim_yuzde !== null && $this->degisim_yuzde > -100) {
            return $this->fiyat / (1 + $this->degisim_yuzde / 100);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'fiyat' => $this->fiyat,
            'alis' => $this->alis,
            'satis' => $this->satis,
            'degisim_yuzde' => $this->degisim_yuzde,
            'elle' => $this->elle,
            'zaman' => $this->zaman?->toIso8601String(),
        ];
    }
}
