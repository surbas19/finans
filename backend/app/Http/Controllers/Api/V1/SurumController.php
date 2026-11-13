<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Canlıdaki sürüm (paketin commit'i). `deploy/SURUM`'u paket-hazirla.sh yazar ("b77c1e8 2026-11-13"); arayüz kendi
 * derleme sürümüyle karşılaştırır, farklıysa uygun anda kendini yeniler. Yerelde dosya yoktur (null: denetim kapalı).
 */
class SurumController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dosya = base_path('deploy/SURUM');
        $surum = is_file($dosya) ? strtok(trim((string) file_get_contents($dosya)), " \t\r\n") : false;

        return response()->json(['surum' => $surum === false || $surum === '' ? null : $surum])
            ->header('Cache-Control', 'no-store');
    }
}
