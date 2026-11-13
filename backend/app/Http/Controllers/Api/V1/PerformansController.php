<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bütün hesapların ya da seçilen hesabın günlük değer / maliyet / kâr-zarar geçmişi (performans grafiği).
 */
class PerformansController extends Controller
{
    public function __invoke(Request $request, PortfoyHesabi $portfoy): JsonResponse
    {
        $veri = $request->validate([
            'hesap_id' => ['nullable', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
            'aralik' => ['nullable', Rule::in(['1A', '3A', '1Y', 'T'])],
        ]);
        $gun = match ($veri['aralik'] ?? '3A') {
            '1A' => 31,
            '3A' => 92,
            '1Y' => 366,
            'T' => null,
        };

        return response()->json([
            // "Tümü" ilk işlemden başlar (gecmis() başlangıcı ilk işleme çeker).
            'gecmis' => $portfoy->gecmis(
                $request->user(),
                isset($veri['hesap_id']) ? (int) $veri['hesap_id'] : null,
                $gun ? now()->subDays($gun) : now()->subYears(100),
                now(),
            ),
        ]);
    }
}
