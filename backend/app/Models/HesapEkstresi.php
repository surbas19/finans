<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Banka hesabı ekstresi: yalnızca yapay zekâ analizi ve önizleme (işlemler, yürüyen bakiye, açılış / kapanış denetimi).
 * Bütçe'ye aktarılmaz. PDF analizden sonra silinir; maskeli JSON (`ai_cikti`) kalır.
 */
class HesapEkstresi extends Model
{
    use HaneyeAit;

    protected $table = 'hesap_ekstreleri';

    protected $fillable = [
        'hane_id', 'durum', 'banka_adi', 'donem_baslangic', 'donem_bitis', 'acilis_bakiyesi', 'kapanis_bakiyesi', 'denetim', 'ai_cikti',
        'dosya_yolu', 'dosya_adi', 'dosya_boyutu', 'sayfa_sayisi', 'dosya_ozeti', 'olusturan_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'donem_baslangic' => 'date',
            'donem_bitis' => 'date',
            'acilis_bakiyesi' => 'decimal:2',
            'kapanis_bakiyesi' => 'decimal:2',
            'denetim' => 'array',
            'ai_cikti' => 'array',
        ];
    }

    /**
     * @return HasMany<EkstreAiCalismasi, $this>
     */
    public function aiCalismalari(): HasMany
    {
        return $this->hasMany(EkstreAiCalismasi::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        $tl = fn (mixed $t): ?float => $t !== null ? Kurus::tl(Kurus::kurus($t)) : null;

        return [
            'id' => $this->id,
            'tur' => 'banka_hesabi',
            'durum' => $this->durum,
            'banka_adi' => $this->banka_adi,
            'donem_baslangic' => $this->donem_baslangic?->toDateString(),
            'donem_bitis' => $this->donem_bitis?->toDateString(),
            'acilis_bakiyesi' => $tl($this->acilis_bakiyesi),
            'kapanis_bakiyesi' => $tl($this->kapanis_bakiyesi),
            'denetim' => $this->denetim,
            'dosya_adi' => $this->dosya_adi,
            'sayfa_sayisi' => $this->sayfa_sayisi,
            'olusturuldu' => $this->created_at?->toIso8601String(),
        ];
    }
}
