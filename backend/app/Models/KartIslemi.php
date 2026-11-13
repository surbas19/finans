<?php

namespace App\Models;

use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kart satırı (ekstreden ya da elle). Tutar her zaman pozitif; gider yönü türden gelir (KartIslemTuru::giderYonu).
 */
class KartIslemi extends Model
{
    use HaneyeAit;

    protected $table = 'kart_islemleri';

    protected $fillable = [
        'hane_id', 'kart_id', 'ekstre_id', 'sira', 'durum', 'islem_tarihi', 'aciklama', 'tutar', 'tur', 'taksit_no',
        'taksit_sayisi', 'taksit_toplam', 'iade_islem_id', 'kategori_id', 'rapor_donemi', 'parmak_izi', 'tekrar_islem_id',
        'ham_metin', 'sayfa', 'okuma', 'fatura_onerisi', 'olusturan_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'durum' => KartIslemDurumu::class,
            'tur' => KartIslemTuru::class,
            'islem_tarihi' => 'date',
            'tutar' => 'decimal:2',
            'taksit_toplam' => 'decimal:2',
            'okuma' => 'array',
            'fatura_onerisi' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Kart, $this>
     */
    public function kart(): BelongsTo
    {
        return $this->belongsTo(Kart::class);
    }

    /**
     * @return BelongsTo<Ekstre, $this>
     */
    public function ekstre(): BelongsTo
    {
        return $this->belongsTo(Ekstre::class);
    }

    /**
     * @return BelongsTo<HarcamaKategorisi, $this>
     */
    public function kategori(): BelongsTo
    {
        return $this->belongsTo(HarcamaKategorisi::class, 'kategori_id');
    }

    /**
     * @return BelongsTo<KartIslemi, $this>
     */
    public function iadeEdilen(): BelongsTo
    {
        return $this->belongsTo(KartIslemi::class, 'iade_islem_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'kart_id' => $this->kart_id,
            'ekstre_id' => $this->ekstre_id,
            'sira' => $this->sira,
            'durum' => $this->durum->value,
            'islem_tarihi' => $this->islem_tarihi?->toDateString(),
            'aciklama' => $this->aciklama,
            'tutar' => $this->tutar !== null ? Kurus::tl(Kurus::kurus($this->tutar)) : null,
            'tur' => $this->tur->value,
            'taksit_no' => $this->taksit_no,
            'taksit_sayisi' => $this->taksit_sayisi,
            'taksit_toplam' => $this->taksit_toplam !== null ? Kurus::tl(Kurus::kurus($this->taksit_toplam)) : null,
            'iade_islem_id' => $this->iade_islem_id,
            'kategori_id' => $this->kategori_id,
            'rapor_donemi' => $this->rapor_donemi,
            'tekrar_islem_id' => $this->tekrar_islem_id,
            'ham_metin' => $this->ham_metin,
            'sayfa' => $this->sayfa,
            'okuma' => $this->okuma ?? ['alanlar' => [], 'uyarilar' => [], 'tutar_adaylari' => []],
            'fatura_onerisi' => $this->fatura_onerisi,
        ];
    }
}
