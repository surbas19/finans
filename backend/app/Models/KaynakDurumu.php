<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KaynakDurumu extends Model
{
    protected $table = 'kaynak_durumlari';

    protected $primaryKey = 'kaynak';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kaynak', 'son_deneme', 'son_basari', 'son_hata', 'son_hata_zamani', 'art_arda_hata', 'guncellenen'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'son_deneme' => 'datetime',
            'son_basari' => 'datetime',
            'son_hata_zamani' => 'datetime',
        ];
    }
}
