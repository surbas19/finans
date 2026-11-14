<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Yapay zekâ yedeği çalışma kaydı (içeriksiz: yalnızca model, sonuç, sayılar, süre).
 */
class EkstreAiCalismasi extends Model
{
    protected $table = 'ekstre_ai_calismalari';

    protected $fillable = ['hane_id', 'ekstre_id', 'user_id', 'model', 'durum', 'hata_kodu', 'sayfa_sayisi', 'kabul_satir', 'reddedilen_satir', 'sure_ms'];
}
