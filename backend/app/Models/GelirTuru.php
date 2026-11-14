<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;

/**
 * Hanenin gelir türü (Maaş, Kira geliri, Ek ders…). Yeni hanede varsayılanlar yazılır (VarsayilanAileKayitlari);
 * kullanıcı ekler / adını değiştirir, gelir ya da kaynakta kullanılan tür silinmez.
 */
class GelirTuru extends Model
{
    use HaneyeAit;

    protected $table = 'gelir_turleri';

    protected $fillable = ['hane_id', 'ad', 'sira'];

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return ['id' => $this->id, 'ad' => $this->ad, 'sira' => $this->sira];
    }
}
