<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Kart;
use App\Services\Aile\DonemGrafikleri;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Faturalar / Kartlar / Gelirler sayfalarındaki dönem dönem çizgi grafikler (yılın 12 ayı, hanenin kayıtları).
 */
class GrafikController extends Controller
{
    public function __invoke(Request $request, DonemGrafikleri $grafikler): JsonResponse
    {
        $veri = $request->validate(['yil' => ['nullable', 'integer', 'between:2000,2100']]);
        $haneId = (int) $request->user()->hane_id;
        $sonuc = $grafikler->yil($haneId, (int) ($veri['yil'] ?? now()->year));
        // Kart renkleri (kartın kendi rengi; seçilmediyse ön yüz kimlikten sabit renk üretir)
        $renkler = Kart::where('hane_id', $haneId)->pluck('renk', 'id');
        $sonuc['kartlar']['kartlar'] = array_map(fn (array $k): array => [...$k, 'renk' => $renkler[$k['kart_id']] ?? null], $sonuc['kartlar']['kartlar']);

        return response()->json($sonuc);
    }
}
