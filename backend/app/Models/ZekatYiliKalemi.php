<?php

namespace App\Models;

use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;

/**
 * Anlık görüntüdeki bir pozisyon (hesap + varlık). Ad ve fiyat kopyadır; fiyatı olmayan kalemin değeri null'dır.
 */
class ZekatYiliKalemi extends Model
{
    public $timestamps = false;

    protected $table = 'zekat_yili_kalemleri';

    protected $fillable = [
        'zekat_yili_id', 'hesap_id', 'hesap_ad', 'varlik_kod', 'varlik_ad', 'varlik_tur', 'miktar', 'birim_fiyat_tl', 'deger',
        'dahil_edilen', 'kapsam_yuzde', 'hesaba_giren', 'dislanma_nedeni',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'miktar' => 'float',
            'birim_fiyat_tl' => 'float',
            'deger' => 'decimal:2',
            'dahil_edilen' => 'boolean',
            'hesaba_giren' => 'decimal:2',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'hesap_id' => $this->hesap_id,
            'hesap_ad' => $this->hesap_ad,
            'varlik_kod' => $this->varlik_kod,
            'varlik_ad' => $this->varlik_ad,
            'varlik_tur' => $this->varlik_tur,
            'miktar' => $this->miktar,
            'birim_fiyat_tl' => $this->birim_fiyat_tl,
            'deger' => $this->deger !== null ? Kurus::tl(Kurus::kurus($this->deger)) : null,
            'dahil_edilen' => $this->dahil_edilen,
            'kapsam_yuzde' => $this->kapsam_yuzde,
            'hesaba_giren' => Kurus::tl(Kurus::kurus($this->hesaba_giren)),
            'dislanma_nedeni' => $this->dislanma_nedeni,
        ];
    }
}
