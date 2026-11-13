<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiyatGecmisi extends Model
{
    protected $table = 'fiyat_gecmisi';

    public $timestamps = false;

    protected $fillable = ['varlik_id', 'tarih', 'kapanis'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tarih' => 'date', 'kapanis' => 'float'];
    }
}
