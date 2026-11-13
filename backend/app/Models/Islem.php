<?php

namespace App\Models;

use App\Enums\IslemTuru;
use Database\Factories\IslemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alış / satış / elde olan. Birim fiyat TL'dir; boşsa maliyet (satışta satış fiyatı) bilinmiyor.
 */
class Islem extends Model
{
    /** @use HasFactory<IslemFactory> */
    use HasFactory;

    protected $table = 'islemler';

    protected $fillable = ['user_id', 'hesap_id', 'varlik_id', 'tur', 'miktar', 'birim_fiyat', 'komisyon', 'tarih', 'not'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tur' => IslemTuru::class,
            'miktar' => 'float',
            'birim_fiyat' => 'float',
            'komisyon' => 'float',
            'tarih' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Hesap, $this>
     */
    public function hesap(): BelongsTo
    {
        return $this->belongsTo(Hesap::class);
    }

    /**
     * @return BelongsTo<Varlik, $this>
     */
    public function varlik(): BelongsTo
    {
        return $this->belongsTo(Varlik::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'hesap_id' => $this->hesap_id,
            'varlik_id' => $this->varlik_id,
            'tur' => $this->tur->value,
            'miktar' => $this->miktar,
            'birim_fiyat' => $this->birim_fiyat,
            'komisyon' => $this->komisyon,
            'tutar' => $this->birim_fiyat !== null ? round($this->miktar * $this->birim_fiyat, 2) : null,
            'tarih' => $this->tarih->toDateString(),
            'not' => $this->not,
        ];
    }
}
