<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;

/** Sağlayıcının ekstrede görünen yazımı (sade metin: Metin::sade) */
class SaglayiciDeseni extends Model
{
    use HaneyeAit;

    protected $table = 'saglayici_desenleri';

    protected $fillable = ['hane_id', 'saglayici_id', 'desen'];
}
