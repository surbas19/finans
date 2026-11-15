<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Yatırım satırının (hesap + varlık) genel toplama / zekâta dahil olmaması. Satır yoksa dahildir.
 */
class PozisyonAyari extends Model
{
    protected $table = 'pozisyon_ayarlari';

    protected $fillable = ['hesap_id', 'varlik_id', 'dahil'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['dahil' => 'boolean'];
    }
}
