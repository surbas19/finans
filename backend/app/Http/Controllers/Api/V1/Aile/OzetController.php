<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Services\Aile\ButceRaporu;
use App\Support\Donem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bütçe aylık özeti (kişi ve hane geliri, kategorilere göre gider, faturalar, gelir − gider) ve uyarılar.
 */
class OzetController extends Controller
{
    public function __invoke(Request $request, ButceRaporu $rapor): JsonResponse
    {
        $veri = $request->validate(['ay' => ['nullable', 'regex:'.Donem::DESEN]], ['ay.regex' => 'Ay YYYY-AA biçiminde olmalı.']);
        $haneId = (int) $request->user()->hane_id;

        return response()->json([
            ...$rapor->aylikOzet($haneId, $veri['ay'] ?? Donem::buAy()),
            'uyarilar' => $rapor->uyarilar($haneId),
        ]);
    }
}
