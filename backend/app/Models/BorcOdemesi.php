<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borç / alacağa yapılan kısmi ödeme (borçta ödediğim, alacakta tahsil ettiğim).
 */
class BorcOdemesi extends Model
{
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
}
