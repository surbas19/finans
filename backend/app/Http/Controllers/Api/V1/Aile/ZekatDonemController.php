<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Services\Zekat\ZekatDonemleri;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zekât yılları "Ramazan'dan Ramazan'a": her yılın gereken, verilen ve kalan zekâtı ile ödemeleri.
 */
class ZekatDonemController extends Controller
{
    public function __invoke(Request $request, ZekatDonemleri $donemler): JsonResponse
    {
        return response()->json(['donemler' => $donemler->liste((int) $request->user()->hane_id)]);
    }
}
