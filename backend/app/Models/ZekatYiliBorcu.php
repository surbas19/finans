<?php

namespace App\Models;

use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;

/**
 * Anlık görüntüde net değerden düşülen borç (kart ekstresi, fatura ya da elle borç) kopyası.
 */
class ZekatYiliBorcu extends Model
{
    public $timestamps = false;

    protected $table = 'zekat_yili_borclari';

    protected $fillable = ['zekat_yili_id', 'kaynak', 'ad', 'tutar'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tutar' => 'decimal:2'];
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return ['kaynak' => $this->kaynak, 'ad' => $this->ad, 'tutar' => Kurus::tl(Kurus::kurus($this->tutar))];
    }
}
