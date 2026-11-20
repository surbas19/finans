<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Yönetici kurtarma kodunun özeti (düz kod saklanmaz). Bkz. `App\Services\KurtarmaKodlari`.
 */
class KurtarmaKodu extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'kurtarma_kodlari';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kullanildi_at' => 'datetime'];
    }
}
