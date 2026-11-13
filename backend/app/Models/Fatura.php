<?php

namespace App\Models;

use App\Enums\FaturaDurumu;
use App\Models\Concerns\HaneyeAit;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fatura. Üç ayrı tarih: hizmet dönemi (`hizmet_donemi`: hizmetin ait olduğu ay; bilinmiyorsa boş), son ödeme tarihi,
 * ödeme tarihi. Kartla ödendiyse bağlı kart satırı (`kart_islemi_id`, tekil): o zaman gideri kart satırıdır, fatura
 * ayrıca gider sayılmaz.
 */
class Fatura extends Model
{
    use HaneyeAit;

    protected $table = 'faturalar';

    protected $fillable = [
        'hane_id', 'saglayici_id', 'fatura_turu_id', 'hizmet_donemi', 'donem_kaynagi', 'tutar', 'son_odeme_tarihi',
        'durum', 'odeme_tarihi', 'kart_islemi_id', 'eslesme', 'kaynak', 'not', 'olusturan_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'durum' => FaturaDurumu::class,
            'tutar' => 'decimal:2',
            'son_odeme_tarihi' => 'date',
            'odeme_tarihi' => 'date',
            'eslesme' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FaturaSaglayicisi, $this>
     */
    public function saglayici(): BelongsTo
    {
        return $this->belongsTo(FaturaSaglayicisi::class, 'saglayici_id');
    }

    /**
     * @return BelongsTo<KartIslemi, $this>
     */
    public function kartIslemi(): BelongsTo
    {
        return $this->belongsTo(KartIslemi::class, 'kart_islemi_id');
    }

    public function gecikmisMi(): bool
    {
        return $this->durum === FaturaDurumu::Bekliyor && $this->son_odeme_tarihi !== null && $this->son_odeme_tarihi->lt(today());
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'saglayici_id' => $this->saglayici_id,
            'fatura_turu_id' => $this->fatura_turu_id,
            'hizmet_donemi' => $this->hizmet_donemi,
            'hizmet_donemi_etiketi' => $this->hizmet_donemi ? Donem::etiket($this->hizmet_donemi) : null,
            'donem_kaynagi' => $this->donem_kaynagi,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'son_odeme_tarihi' => $this->son_odeme_tarihi?->toDateString(),
            'durum' => $this->durum->value,
            'gecikmis' => $this->gecikmisMi(),
            'odeme_tarihi' => $this->odeme_tarihi?->toDateString(),
            'kart_islemi_id' => $this->kart_islemi_id,
            'eslesme' => $this->eslesme,
            'kaynak' => $this->kaynak,
            'not' => $this->not,
        ];
    }
}
