<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bütçeden ayrılan tasarruf: portföy hesabına yazılan alış işleminin bütçedeki dönem bağı. Gider sayılmaz.
 */
class Tasarruf extends Model
{
    use HaneyeAit;

    protected $table = 'tasarruflar';

    protected $fillable = ['hane_id', 'uye_id', 'hesap_id', 'islem_id', 'gun_id', 'tarih', 'donem', 'tutar_tl', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tarih' => 'date', 'tutar_tl' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Islem, $this>
     */
    public function islem(): BelongsTo
    {
        return $this->belongsTo(Islem::class);
    }

    /**
     * @return BelongsTo<TasarrufGunu, $this>
     */
    public function gun(): BelongsTo
    {
        return $this->belongsTo(TasarrufGunu::class, 'gun_id');
    }

    /**
     * @return BelongsTo<Hesap, $this>
     */
    public function hesap(): BelongsTo
    {
        return $this->belongsTo(Hesap::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'uye_id' => $this->uye_id,
            'hesap_id' => $this->hesap_id,
            'hesap_ad' => $this->hesap?->ad,
            'islem_id' => $this->islem_id,
            'gun_id' => $this->gun_id,
            'gun_ad' => $this->gun?->ad,
            'varlik_id' => $this->islem?->varlik_id,
            'varlik_ad' => $this->islem?->varlik?->ad,
            'varlik_tur' => $this->islem?->varlik?->tur->value,
            'miktar' => $this->islem?->miktar,
            'tarih' => $this->tarih->toDateString(),
            'donem' => $this->donem,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar_tl)),
            'not' => $this->not,
        ];
    }
}
