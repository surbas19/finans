<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Services\Aile\NakitKaynaklari;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nakit harcama formları için düşülebilecek kaynaklar: ev nakdi bakiyesi ve hane hesaplarının Nakit (TL) bakiyeleri.
 */
class OdemeKaynaklariController extends Controller
{
    public function __invoke(Request $request, NakitKaynaklari $kaynaklar): JsonResponse
    {
        return response()->json($kaynaklar->kaynaklar($request->user()));
    }
}
