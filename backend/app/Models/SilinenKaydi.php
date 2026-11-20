<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Silinen kaydın arşivi (anlık görüntü). Yalnızca `SilinenArsivi` yazar; güncellenmez, silinmez.
 */
class SilinenKaydi extends Model
{
    public const TURLER = [
        'kart_islemi' => 'Kart işlemi',
        'gider' => 'Gider',
        'gelir' => 'Gelir',
        'tasarruf' => 'Tasarruf',
        'borc' => 'Borç / alacak',
        'borc_odemesi' => 'Borç ödemesi',
        'ev_nakdi' => 'Ev nakdi',
        'fatura' => 'Fatura',
        'ekstre' => 'Ekstre',
        'portfoy_islemi' => 'Portföy işlemi',
        'kart' => 'Kart',
        'hesap' => 'Hesap',
    ];

    public $timestamps = false;

    protected $table = 'silinen_kayitlar';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['icerik' => 'array', 'alt' => 'array', 'tutar' => 'decimal:2', 'olusturuldu_at' => 'datetime', 'silindi_at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(bool $ayrinti = false): array
    {
        return [
            'id' => $this->id,
            'tur' => $this->tur,
            'tur_etiketi' => self::TURLER[$this->tur] ?? $this->tur,
            'baslik' => $this->baslik,
            'tutar' => $this->tutar !== null ? (float) $this->tutar : null,
            'olusturan_ad' => $this->olusturan_ad,
            'olusturuldu_at' => $this->olusturuldu_at?->toIso8601String(),
            'silen_ad' => $this->silen_ad,
            'silindi_at' => $this->silindi_at->toIso8601String(),
            'neden' => $this->neden,
            'alt_sayisi' => is_array($this->alt) ? count($this->alt) : 0,
            ...($ayrinti ? ['icerik' => $this->icerik, 'alt' => $this->alt ?? []] : []),
        ];
    }
}
