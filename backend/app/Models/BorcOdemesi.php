<?php

namespace App\Models;

use App\Models\Concerns\Arsivlenir;
use App\Models\Concerns\DefterIzler;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borç / alacağa yapılan kısmi ödeme (borçta ödediğim, alacakta tahsil ettiğim).
 */
class BorcOdemesi extends Model
{
    use Arsivlenir;
    use DefterIzler;

    protected string $arsivTuru = 'borc_odemesi';

    public function arsivBaslik(): string
    {
        return 'Ödeme · '.($this->borc?->kisi ?? '').' · '.$this->tarih?->format('d.m.Y');
    }

    use HaneyeAit;

    protected $table = 'borc_odemeleri';

    protected $fillable = ['hane_id', 'borc_id', 'tutar', 'tarih', 'not'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tutar' => 'decimal:2', 'tarih' => 'date'];
    }

    /**
     * @return BelongsTo<Borc, $this>
     */
    public function borc(): BelongsTo
    {
        return $this->belongsTo(Borc::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return ['id' => $this->id, 'tutar' => Kurus::tl(Kurus::kurus($this->tutar)), 'tarih' => $this->tarih->toDateString(), 'not' => $this->not];
    }

    /** @return array<string, mixed>|null Defter'in sonradan değişiklik kaydı için bilgi */
    public function defterBilgisi(): ?array
    {
        return $this->hane_id === null || $this->tarih === null ? null : [
            'hane_id' => (int) $this->hane_id, 'donem' => $this->tarih->format('Y-m'), 'tur' => 'borc_odemesi', 'kayit_id' => $this->getKey(),
            'baslik' => 'Borç / alacak ödemesi', 'tarih' => $this->tarih->toDateString(), 'tutar' => $this->tutar, 'not' => null,
        ];
    }
}
