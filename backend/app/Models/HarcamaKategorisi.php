<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HarcamaKategorisi extends Model
{
    use HaneyeAit;

    protected $table = 'harcama_kategorileri';

    protected $fillable = ['hane_id', 'ad', 'ikon', 'renk', 'sira', 'fatura'];

    /** Kategori renkleri (ön yüzdeki kart paletiyle aynı 12 PrimeVue rengi) */
    public const RENKLER = ['teal', 'sky', 'blue', 'indigo', 'violet', 'pink', 'rose', 'red', 'orange', 'amber', 'emerald', 'slate'];

    /** Hanede henüz kullanılmayan ilk palet rengi (tükenince sırayla döner): her kategori ayrışan bir renk alır */
    public static function sonrakiRenk(int $haneId): string
    {
        $kullanilan = static::where('hane_id', $haneId)->pluck('renk')->filter()->all();
        foreach (self::RENKLER as $renk) {
            if (! in_array($renk, $kullanilan, true)) {
                return $renk;
            }
        }

        return self::RENKLER[count($kullanilan) % count(self::RENKLER)];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fatura' => 'boolean'];
    }

    /**
     * @return HasMany<KategoriKurali, $this>
     */
    public function kurallar(): HasMany
    {
        return $this->hasMany(KategoriKurali::class, 'kategori_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'ikon' => $this->ikon,
            'renk' => $this->renk,
            'fatura' => $this->fatura,
            'sira' => $this->sira,
        ];
    }
}
