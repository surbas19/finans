<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fatura türü: sistem (elektrik, su, doğalgaz; `hane_id` boş) ya da hanenin eklediği. Anahtar kelimeler sağlayıcı
 * tanımlı değilken zayıf kanıttır.
 */
class FaturaTuru extends Model
{
    protected $table = 'fatura_turleri';

    protected $fillable = ['hane_id', 'ad', 'ikon', 'anahtar_kelimeler', 'sira'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['anahtar_kelimeler' => 'array'];
    }

    /**
     * Hanenin görebildiği türler: sistem türleri + kendi türleri.
     *
     * @param  Builder<FaturaTuru>  $sorgu
     */
    public function scopeHaneIcin(Builder $sorgu, ?int $haneId): void
    {
        $sorgu->where(fn ($q) => $q->whereNull('hane_id')->orWhere('hane_id', $haneId ?? 0));
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
            'sistem' => $this->hane_id === null,
            'anahtar_kelimeler' => $this->anahtar_kelimeler ?? [],
        ];
    }
}
