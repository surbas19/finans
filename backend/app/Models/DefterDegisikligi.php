<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kapanmış aya ait kayıtta sonradan yapılan değişiklik. Yalnızca `DefterGecmisi` yazar; güncellenmez, silinmez.
 */
class DefterDegisikligi extends Model
{
    public $timestamps = false;

    protected $table = 'defter_degisiklikleri';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kayit_tarihi' => 'date', 'eski_tutar' => 'decimal:2', 'yeni_tutar' => 'decimal:2', 'yapildi_at' => 'datetime'];
    }
}
