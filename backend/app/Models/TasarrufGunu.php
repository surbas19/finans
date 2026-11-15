<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Altın / döviz günü şablonu: her ay `ay_gunu`nda `varlik` cinsinden `miktar` alınır (alım kaydı `Tasarruf`).
 */
class TasarrufGunu extends Model
{
    use HaneyeAit;

    protected $table = 'tasarruf_gunleri';

    protected $fillable = ['hane_id', 'hesap_id', 'varlik_id', 'ad', 'miktar', 'ay_gunu', 'baslangic', 'ay_sayisi', 'bizde_ay', 'aktif', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['baslangic' => 'date', 'aktif' => 'boolean', 'ay_gunu' => 'integer', 'ay_sayisi' => 'integer'];
    }

    /**
     * @return BelongsTo<Varlik, $this>
     */
    public function varlik(): BelongsTo
    {
        return $this->belongsTo(Varlik::class);
    }

    /**
     * @return BelongsTo<Hesap, $this>
     */
    public function hesap(): BelongsTo
    {
        return $this->belongsTo(Hesap::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'hesap_id' => $this->hesap_id,
            'hesap_ad' => $this->hesap?->ad,
            'varlik_id' => $this->varlik_id,
            'varlik_ad' => $this->varlik?->ad,
            'varlik_kod' => $this->varlik?->kod,
            'varlik_birim' => $this->varlik?->birim,
            'miktar' => rtrim(rtrim(number_format((float) $this->miktar, 8, '.', ''), '0'), '.'),
            'ay_gunu' => $this->ay_gunu,
            'baslangic' => $this->baslangic->toDateString(),
            'ay_sayisi' => $this->ay_sayisi,
            'bizde_ay' => $this->bizde_ay,
            'aktif' => $this->aktif,
        ];
    }
}
