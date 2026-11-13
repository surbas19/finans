<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HarcamaKategorisi extends Model
{
    use HaneyeAit;

    protected $table = 'harcama_kategorileri';

    protected $fillable = ['hane_id', 'ad', 'ikon', 'renk', 'sira', 'fatura'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fatura' => 'boolean'];
    }

    /**
     * @return HasMany<KategoriKurali, $this>
     */
    public function kurallar(): HasMany
    {
        return $this->hasMany(KategoriKurali::class, 'kategori_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'ikon' => $this->ikon,
            'renk' => $this->renk,
            'fatura' => $this->fatura,
            'sira' => $this->sira,
        ];
    }
}
