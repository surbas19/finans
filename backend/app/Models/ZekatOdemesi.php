<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verilen zekât. Alıcı serbest ad; IBAN ya da hesap numarası tutulmaz. Bütçe'de yalnızca bilgi satırıdır (kalanı etkilemez).
 */
class ZekatOdemesi extends Model
{
    use HaneyeAit;

    protected $table = 'zekat_odemeleri';

    protected $fillable = ['hane_id', 'zekat_yili_id', 'uye_id', 'tarih', 'tutar', 'alici', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tarih' => 'date', 'tutar' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<ZekatYili, $this>
     */
    public function yil(): BelongsTo
    {
        return $this->belongsTo(ZekatYili::class, 'zekat_yili_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'zekat_yili_id' => $this->zekat_yili_id,
            'uye_id' => $this->uye_id,
            'tarih' => $this->tarih->toDateString(),
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'alici' => $this->alici,
            'not' => $this->not,
        ];
    }
}
