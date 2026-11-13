<?php

namespace App\Services\Aile;

use App\Enums\VarlikTuru;
use App\Models\FiyatGecmisi;
use App\Models\Varlik;
use App\Support\Kurus;
use Illuminate\Support\Carbon;

/**
 * Döviz gelirinin TL karşılığı için kur: katalogdaki dövizin o güne (en çok 7 gün önceye kadar) kapanışı;
 * bugün için güncel fiyat. Bulunamazsa `null` (kullanıcı TL karşılığını kendisi girer). Hesap kuruşla yapılır.
 */
class DovizKuru
{
    /** @return list<string> Katalogdaki döviz kodları (TRY dışı geçerli para birimleri) */
    public function kodlar(): array
    {
        return Varlik::where('tur', VarlikTuru::Doviz->value)->where('aktif', true)->orderBy('sira')->pluck('kod')->all();
    }

    /** Kur, 6 ondalıklı metin ("32.401500") ya da null */
    public function kur(string $kod, string $tarih): ?string
    {
        $varlik = Varlik::where('tur', VarlikTuru::Doviz->value)->where('kod', $kod)->with('fiyat')->first();
        if (! $varlik) {
            return null;
        }
        $kapanis = FiyatGecmisi::where('varlik_id', $varlik->id)
            ->where('tarih', '<=', $tarih)->where('tarih', '>=', Carbon::parse($tarih)->subDays(7)->toDateString())
            ->orderByDesc('tarih')->value('kapanis');
        if ($kapanis === null && $tarih === now()->toDateString() && $varlik->fiyat) {
            $kapanis = $varlik->fiyat->fiyat;
        }

        return $kapanis !== null ? number_format((float) $kapanis, 6, '.', '') : null;
    }

    /**
     * Tutar (kuruş) × kur → TL kuruş, yarım kuruş yukarı yuvarlanır. Taşmasın diye kur tam ve kesirli kısma bölünür.
     */
    public static function tlKurus(int $tutarKurus, string $kur): int
    {
        [$tam, $kesir] = array_pad(explode('.', $kur), 2, '0');
        $milyonda = (int) str_pad(substr($kesir, 0, 6), 6, '0');

        return $tutarKurus * (int) $tam + intdiv($tutarKurus * $milyonda + 500000, 1000000);
    }

    public static function tlDecimal(string $tutar, string $kur): string
    {
        return Kurus::decimal(self::tlKurus(Kurus::kurus($tutar), $kur));
    }
}
