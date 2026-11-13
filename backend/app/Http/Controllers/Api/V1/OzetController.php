<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Fiyat;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ana sayfa: bütün hesaplar ya da seçilen hesap.
 */
class OzetController extends Controller
{
    public function __invoke(Request $request, PortfoyHesabi $portfoy): JsonResponse
    {
        $veri = $request->validate([
            'hesap_id' => ['nullable', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
        ]);

        return response()->json([
            ...$portfoy->hesapla($request->user(), isset($veri['hesap_id']) ? (int) $veri['hesap_id'] : null),
            'son_guncelleme' => Fiyat::where('elle', false)->max('updated_at'),
        ]);
    }
}
