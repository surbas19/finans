<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\ZekatBorcu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Elle girilen borçlar (kartta ve faturada görünmeyenler). "Borç düşme" ayarı açıksa zekât hesabında net değerden düşer.
 */
class ZekatBorcController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'borclar' => ZekatBorcu::hanenin($request->user())->orderBy('id')->get()->map(fn (ZekatBorcu $b): array => $b->ozet())->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:120'],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'vade_tarihi' => ['nullable', 'date'],
            'not' => ['nullable', 'string', 'max:255'],
        ], [], ['ad' => 'borç adı', 'tutar' => 'tutar', 'vade_tarihi' => 'vade']);
        $veri['ad'] = trim($veri['ad']);
        $borc = ZekatBorcu::create([...$veri, 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json(['borc' => $borc->ozet(), 'message' => 'Borç eklendi.'], 201);
    }

    public function destroy(ZekatBorcu $borc): JsonResponse
    {
        Gate::authorize('hane-kaydi', $borc);
        $borc->delete();

        return response()->json(['message' => 'Borç silindi.']);
    }
}
