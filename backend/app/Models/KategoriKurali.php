<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;

/** Açıklamada `desen` (sade metin) geçen kart satırı bu kategoriye girer (KategoriBulucu). */
class KategoriKurali extends Model
{
    use HaneyeAit;

    protected $table = 'kategori_kurallari';

    protected $fillable = ['hane_id', 'kategori_id', 'desen'];
}
