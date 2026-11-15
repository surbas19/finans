<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Services\Aile\ButceRaporu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bütçe → Tasarruf sekmesi: yılın 12 ayı için gelir, gider, tasarruf, oranlar ve ayın tasarruf kalemleri.
 */
class TasarrufOzetController extends Controller
{
    public function __invoke(Request $request, ButceRaporu $rapor): JsonResponse
    {
        $veri = $request->validate(['yil' => ['nullable', 'integer', 'between:2000,2100']]);

        return response()->json($rapor->tasarrufDonemleri((int) $request->user()->hane_id, (int) ($veri['yil'] ?? now()->year)));
    }
}
