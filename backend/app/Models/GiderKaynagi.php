<?php

namespace App\Models;

use App\Enums\GelirSikligi;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;

/**
 * Düzenli ödemenin tanımı (okul, bakıcı, aidat…). Kaynaktan aylık beklenen gider çıkar (GiderTakvimi).
 */
class GiderKaynagi extends Model
{
    use HaneyeAit;

    protected $table = 'gider_kaynaklari';

    protected $fillable = ['hane_id', 'uye_id', 'kategori_id', 'ad', 'siklik', 'beklenen_tutar', 'gun', 'baslangic', 'bitis', 'aktif', 'not'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'siklik' => GelirSikligi::class,
            'beklenen_tutar' => 'decimal:2',
            'baslangic' => 'date',
            'bitis' => 'date',
            'aktif' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'uye_id' => $this->uye_id,
            'kategori_id' => $this->kategori_id,
            'ad' => $this->ad,
            'siklik' => $this->siklik->value,
            'beklenen_tutar' => $this->beklenen_tutar !== null ? Kurus::tl(Kurus::kurus($this->beklenen_tutar)) : null,
            'gun' => $this->gun,
            'baslangic' => $this->baslangic?->toDateString(),
            'bitis' => $this->bitis?->toDateString(),
            'aktif' => $this->aktif,
            'not' => $this->not,
        ];
    }
}
