<?php

namespace App\Models;

use App\Enums\VarlikTuru;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ortak katalog: fiyat bir kez çekilir, bütün kullanıcılar aynı kaydı kullanır.
 */
class Varlik extends Model
{
    protected $table = 'varliklar';

    protected $fillable = ['tur', 'kod', 'ad', 'birim', 'para_birimi', 'kaynak', 'kaynak_kodu', 'gecikmeli', 'aktif', 'sira', 'ekleyen_id', 'gecmis_tarihi'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tur' => VarlikTuru::class,
            'gecikmeli' => 'boolean',
            'aktif' => 'boolean',
            'gecmis_tarihi' => 'date',
        ];
    }

    /**
     * @return HasOne<Fiyat, $this>
     */
    public function fiyat(): HasOne
    {
        return $this->hasOne(Fiyat::class);
    }

    /**
     * @return HasMany<FiyatGecmisi, $this>
     */
    public function gecmis(): HasMany
    {
        return $this->hasMany(FiyatGecmisi::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        $fiyat = $this->relationLoaded('fiyat') ? $this->fiyat : null;

        return [
            'id' => $this->id,
            'tur' => $this->tur->value,
            'kod' => $this->kod,
            'ad' => $this->ad,
            'birim' => $this->birim,
            'para_birimi' => $this->para_birimi,
            'kaynak' => $this->kaynak,
            'gecikmeli' => $this->gecikmeli,
            'fiyat' => $fiyat?->ozet(),
        ];
    }
}
