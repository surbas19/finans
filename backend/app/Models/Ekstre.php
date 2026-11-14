<?php

namespace App\Models;

use App\Enums\EkstreDurumu;
use App\Models\Concerns\HaneyeAit;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kredi kartı ekstresi. PDF özel diskte saklanır (yalnızca hane açar); ekstre silinince dosya da silinir.
 * Dönem bilgisi alanlarının kaynağı (PDF / kullanıcı) `alan_kaynaklari`'nda; PDF'de bulunamayan alan `okunamayan`'da.
 */
class Ekstre extends Model
{
    use HaneyeAit;

    protected $table = 'ekstreler';

    protected $fillable = [
        'hane_id', 'kart_id', 'durum', 'donem_baslangic', 'kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu',
        'asgari_odeme', 'alan_kaynaklari', 'okunamayan', 'banka_profili', 'denetim', 'kalan_taksit', 'odendi', 'onayli_kesim_tarihi', 'dosya_yolu', 'dosya_adi',
        'dosya_boyutu', 'sayfa_sayisi', 'dosya_ozeti', 'olusturan_id', 'onaylayan_id', 'onaylandi_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'durum' => EkstreDurumu::class,
            'donem_baslangic' => 'date',
            'kesim_tarihi' => 'date',
            'son_odeme_tarihi' => 'date',
            'onayli_kesim_tarihi' => 'date',
            'donem_borcu' => 'decimal:2',
            'asgari_odeme' => 'decimal:2',
            'alan_kaynaklari' => 'array',
            'okunamayan' => 'array',
            'denetim' => 'array',
            'kalan_taksit' => 'decimal:2',
            'odendi' => 'boolean',
            'onaylandi_at' => 'datetime',
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
     * @return HasMany<KartIslemi, $this>
     */
    public function islemler(): HasMany
    {
        return $this->hasMany(KartIslemi::class)->orderBy('sira')->orderBy('id');
    }

    /** "Şubat 2026" (kesim ayı) ya da null */
    public function donemEtiketi(): ?string
    {
        return $this->kesim_tarihi ? Donem::etiket(Donem::tarihten($this->kesim_tarihi)) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'kart_id' => $this->kart_id,
            'durum' => $this->durum->value,
            'donem' => $this->kesim_tarihi ? Donem::tarihten($this->kesim_tarihi) : null,
            'donem_etiketi' => $this->donemEtiketi(),
            'donem_baslangic' => $this->donem_baslangic?->toDateString(),
            'kesim_tarihi' => $this->kesim_tarihi?->toDateString(),
            'son_odeme_tarihi' => $this->son_odeme_tarihi?->toDateString(),
            'donem_borcu' => $this->donem_borcu !== null ? Kurus::tl(Kurus::kurus($this->donem_borcu)) : null,
            'asgari_odeme' => $this->asgari_odeme !== null ? Kurus::tl(Kurus::kurus($this->asgari_odeme)) : null,
            'alan_kaynaklari' => $this->alan_kaynaklari ?? [],
            'okunamayan' => $this->okunamayan ?? [],
            'banka_profili' => $this->banka_profili,
            'denetim' => $this->denetim,
            'kalan_taksit' => $this->kalan_taksit !== null ? Kurus::tl(Kurus::kurus($this->kalan_taksit)) : null,
            'odendi' => $this->odendi,
            'dosya_var' => $this->dosya_yolu !== null,
            'dosya_adi' => $this->dosya_adi,
            'sayfa_sayisi' => $this->sayfa_sayisi,
            'onaylandi_at' => $this->onaylandi_at?->toIso8601String(),
            'olusturuldu' => $this->created_at?->toIso8601String(),
        ];
    }
}
