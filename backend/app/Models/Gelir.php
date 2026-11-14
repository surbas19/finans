<?php

namespace App\Models;

use App\Enums\GelirDuzeni;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gerçekleşen gelir. `donem` ait olduğu ay (geçmiş ay girilebilir, bugünkü aya kaymaz); `tarih` elde edildiği gün.
 * Raporlar `tl_tutar` ile toplanır (kuruş).
 */
class Gelir extends Model
{
    use HaneyeAit;

    protected $table = 'gelirler';

    protected $fillable = ['hane_id', 'uye_id', 'gelir_kaynagi_id', 'gelir_turu_id', 'duzen', 'aciklama', 'tutar', 'para_birimi', 'tl_tutar', 'kur', 'tarih', 'donem', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duzen' => GelirDuzeni::class,
            'tutar' => 'decimal:2',
            'tl_tutar' => 'decimal:2',
            'kur' => 'decimal:6',
            'tarih' => 'date',
        ];
    }

    /**
     * @return BelongsTo<HaneUyesi, $this>
     */
    public function uye(): BelongsTo
    {
        return $this->belongsTo(HaneUyesi::class, 'uye_id');
    }

    /**
     * @return BelongsTo<GelirKaynagi, $this>
     */
    public function kaynak(): BelongsTo
    {
        return $this->belongsTo(GelirKaynagi::class, 'gelir_kaynagi_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'uye_id' => $this->uye_id,
            'gelir_kaynagi_id' => $this->gelir_kaynagi_id,
            'gelir_turu_id' => $this->gelir_turu_id,
            'duzen' => $this->duzen->value,
            'aciklama' => $this->aciklama,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'para_birimi' => $this->para_birimi,
            'tl_tutar' => Kurus::tl(Kurus::kurus($this->tl_tutar)),
            'kur' => $this->kur !== null ? (float) $this->kur : null,
            'tarih' => $this->tarih->toDateString(),
            'donem' => $this->donem,
            'not' => $this->not,
        ];
    }
}
