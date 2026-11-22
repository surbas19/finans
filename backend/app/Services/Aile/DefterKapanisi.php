<?php

namespace App\Services\Aile;

use App\Models\DefterAyi;
use App\Support\Donem;
use Illuminate\Database\QueryException;

/**
 * Ayın defterini dondurur (mühürler) ve okur. Kapanmış ay (bu aydan önceki) ilk gerektiğinde bir kez hesaplanıp
 * `defter_aylari`'na yazılır; sonra hiç değişmez. İçinde bulunulan ay canlı hesaplanır. Mühür, ayın ilk
 * değişikliğinden hemen önce (`DefterGecmisi`) ya da ayın ilk günü `defter:kapat` komutuyla atılır.
 */
class DefterKapanisi
{
    public function __construct(private DefterHazirla $hazirla) {}

    public static function kapaliMi(string $donem): bool
    {
        return $donem < Donem::buAy();
    }

    public function muhurla(int $haneId, string $donem): DefterAyi
    {
        $var = DefterAyi::where('hane_id', $haneId)->where('donem', $donem)->first();
        if ($var) {
            return $var;
        }
        $icerik = $this->hazirla->olustur($haneId, $donem);
        try {
            return DefterAyi::create(['hane_id' => $haneId, 'donem' => $donem, 'muhurlendi_at' => now(), 'icerik' => $icerik]);
        } catch (QueryException) {
            // Aynı anda başka istek mühürledi: onun kaydı geçerlidir
            return DefterAyi::where('hane_id', $haneId)->where('donem', $donem)->firstOrFail();
        }
    }

    /**
     * @return array{kapali: bool, muhurlendi: ?string, icerik: array<string, mixed>}
     */
    public function oku(int $haneId, string $donem): array
    {
        if (! self::kapaliMi($donem)) {
            return ['kapali' => false, 'muhurlendi' => null, 'icerik' => $this->hazirla->olustur($haneId, $donem)];
        }
        $ay = $this->muhurla($haneId, $donem);

        return ['kapali' => true, 'muhurlendi' => $ay->muhurlendi_at->toIso8601String(), 'icerik' => $ay->icerik];
    }
}
