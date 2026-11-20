<?php

namespace App\Models;

use App\Models\Concerns\Arsivlenir;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;

/**
 * Ev nakdi kasasına elle para ekleme / çıkarma. Ev harcamaları (`duzenli_giderler.odeme_yeri = ev`) ayrıca düşer.
 */
class EvNakdiHareketi extends Model
{
    use Arsivlenir;

    protected string $arsivTuru = 'ev_nakdi';

    public function arsivBaslik(): string
    {
        return ($this->tur === self::EKLEME ? 'Ev nakdine eklendi' : 'Ev nakdinden çıkarıldı').' · '.$this->tarih?->format('d.m.Y');
    }

    use HaneyeAit;

    public const EKLEME = 'ekleme';

    public const CIKARMA = 'cikarma';

    protected $table = 'ev_nakdi_hareketleri';

    protected $fillable = ['hane_id', 'tur', 'tutar', 'tarih', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tutar' => 'decimal:2', 'tarih' => 'date'];
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'tur' => $this->tur,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'tarih' => $this->tarih->toDateString(),
            'not' => $this->not,
        ];
    }
}
