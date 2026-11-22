<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kapanmış ayın değişmez anlık görüntüsü. Yalnızca `DefterKapanisi` yazar; güncellenmez, silinmez.
 */
class DefterAyi extends Model
{
    public $timestamps = false;

    protected $table = 'defter_aylari';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['icerik' => 'array', 'muhurlendi_at' => 'datetime'];
    }
}
