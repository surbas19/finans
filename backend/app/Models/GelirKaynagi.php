<?php

namespace App\Models;

use App\Enums\GelirDuzeni;
use App\Enums\GelirSikligi;
use App\Enums\GelirTuru;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gelirin tanımı (maaş, kira, ek iş…). Düzenli kaynaktan aylık beklenen gelir çıkar (GelirTakvimi).
 */
class GelirKaynagi extends Model
{
    use HaneyeAit;

    protected $table = 'gelir_kaynaklari';

    protected $fillable = ['hane_id', 'uye_id', 'tur', 'ad', 'duzen', 'siklik', 'beklenen_tutar', 'para_birimi', 'gun', 'baslangic', 'bitis', 'aktif', 'not'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tur' => GelirTuru::class,
            'duzen' => GelirDuzeni::class,
            'siklik' => GelirSikligi::class,
            'beklenen_tutar' => 'decimal:2',
            'baslangic' => 'date',
            'bitis' => 'date',
            'aktif' => 'boolean',
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
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'uye_id' => $this->uye_id,
            'tur' => $this->tur->value,
            'ad' => $this->ad,
            'duzen' => $this->duzen->value,
            'siklik' => $this->siklik?->value,
            'beklenen_tutar' => $this->beklenen_tutar !== null ? Kurus::tl(Kurus::kurus($this->beklenen_tutar)) : null,
            'para_birimi' => $this->para_birimi,
            'gun' => $this->gun,
            'baslangic' => $this->baslangic?->toDateString(),
            'bitis' => $this->bitis?->toDateString(),
            'aktif' => $this->aktif,
            'not' => $this->not,
        ];
    }
}
