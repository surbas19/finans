<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;

/**
 * Elle girilen borç (kişiye, kuruma; kart ve faturada görünmeyen). "Borç düşme" açıksa zekât hesabında net değerden düşer.
 */
class ZekatBorcu extends Model
{
    use HaneyeAit;

    protected $table = 'zekat_borclari';

    protected $fillable = ['hane_id', 'ad', 'tutar', 'vade_tarihi', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tutar' => 'decimal:2', 'vade_tarihi' => 'date'];
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'vade_tarihi' => $this->vade_tarihi?->toDateString(),
            'not' => $this->not,
        ];
    }
}
