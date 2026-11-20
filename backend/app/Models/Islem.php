<?php

namespace App\Models;

use App\Enums\IslemTuru;
use App\Models\Concerns\Arsivlenir;
use Database\Factories\IslemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alış / satış / elde olan. Birim fiyat TL'dir; boşsa maliyet (satışta satış fiyatı) bilinmiyor.
 * Temettüde `miktar` net TL, `komisyon` stopaj; bedelsizde `miktar` oran yüzdesi (IslemTuru).
 * Eşleşik kayıtlar `aktarim_kodu` taşır; `bag_turu` ne olduklarını söyler (`aktarim` | `temettu`).
 */
class Islem extends Model
{
    use Arsivlenir;

    protected string $arsivTuru = 'portfoy_islemi';

    /** Nakit harcamasının hesaba yazdığı satış gider kaydında zaten arşivlenir */
    public function arsivlenecekMi(): bool
    {
        return $this->bag_turu !== 'harcama';
    }

    public function arsivBaslik(): string
    {
        return $this->tur->etiket().' · '.($this->varlik?->kod ?? 'varlık').' · '.$this->miktar.' · '.$this->tarih?->format('d.m.Y');
    }

    public function arsivTutar(): ?string
    {
        return $this->birim_fiyat === null ? null : number_format($this->miktar * $this->birim_fiyat, 2, '.', '');
    }

    /** @use HasFactory<IslemFactory> */
    use HasFactory;

    protected $table = 'islemler';

    protected $fillable = ['user_id', 'hesap_id', 'varlik_id', 'tur', 'miktar', 'birim_fiyat', 'komisyon', 'tarih', 'not', 'aktarim_kodu', 'bag_turu', 'dahil'];

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
            'dahil' => 'boolean',
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
        // Bireysel emeklilikte miktar toplam tutardır; birim fiyat maliyet / tutar oranı (kâr yüzdesinden)
        $deger = $this->tur === IslemTuru::Deger;
        // Temettüde miktar net TL (tutar), komisyon stopaj; bedelsizde miktar oran yüzdesi (tutar yok)
        $temettu = $this->tur === IslemTuru::Temettu;
        $bedelsiz = $this->tur === IslemTuru::Bedelsiz;

        return [
            'id' => $this->id,
            'hesap_id' => $this->hesap_id,
            'varlik_id' => $this->varlik_id,
            'tur' => $this->tur->value,
            'miktar' => $this->miktar,
            'birim_fiyat' => $this->birim_fiyat,
            'komisyon' => $this->komisyon,
            'tutar' => match (true) {
                $deger, $temettu => round($this->miktar, 2),
                $bedelsiz => null,
                default => $this->birim_fiyat !== null ? round($this->miktar * $this->birim_fiyat, 2) : null,
            },
            'stopaj' => $temettu ? round($this->komisyon, 2) : null,
            'brut' => $temettu ? round($this->miktar + $this->komisyon, 2) : null,
            'oran_yuzde' => $bedelsiz ? round($this->miktar, 4) : null,
            'kar_yuzde' => $deger && $this->birim_fiyat ? round((1 / $this->birim_fiyat - 1) * 100, 2) : null,
            'tarih' => $this->tarih->toDateString(),
            'not' => $this->not,
            'aktarim_kodu' => $this->aktarim_kodu,
            'bag_turu' => $this->bag_turu,
            // İşlem numarası (kalıcı, kimlikten), genel toplama dahil mi ve kaydın girildiği zaman (otomatik)
            'no' => 'İ-'.$this->id,
            'dahil' => $this->dahil ?? true,
            'islem_zamani' => $this->created_at?->toIso8601String(),
        ];
    }
}
