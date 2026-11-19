<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;

/**
 * Gerçekleşen kartsız gider (düzenli ödeme ya da tek seferlik). `donem` ait olduğu ay, `tarih` ödendiği gün.
 */
class DuzenliGider extends Model
{
    use HaneyeAit;

    protected $table = 'duzenli_giderler';

    protected $fillable = ['hane_id', 'gider_kaynagi_id', 'kategori_id', 'aciklama', 'tutar', 'tarih', 'odeme_yeri', 'hesap_id', 'islem_id', 'donem', 'not', 'olusturan_id'];

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
            'gider_kaynagi_id' => $this->gider_kaynagi_id,
            'kategori_id' => $this->kategori_id,
            'aciklama' => $this->aciklama,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'tarih' => $this->tarih->toDateString(),
            'odeme_yeri' => $this->odeme_yeri,
            'hesap_id' => $this->hesap_id,
            'donem' => $this->donem,
            'not' => $this->not,
        ];
    }
}
