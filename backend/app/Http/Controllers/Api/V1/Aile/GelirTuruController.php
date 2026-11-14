<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Gelir;
use App\Models\GelirKaynagi;
use App\Models\GelirTuru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hanenin gelir türleri: ekle, adını değiştir, sil. Gelir ya da kaynakta kullanılan tür silinmez.
 */
class GelirTuruController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'turler' => GelirTuru::hanenin($request->user())->orderBy('sira')->orderBy('ad')->get()
                ->map(fn (GelirTuru $t): array => $t->ozet()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $haneId = $request->user()->hane_id;
        $tur = GelirTuru::create([
            'ad' => $this->ad($request, null),
            'hane_id' => $haneId,
            'sira' => (int) GelirTuru::where('hane_id', $haneId)->max('sira') + 1,
        ]);

        return response()->json(['tur' => $tur->ozet(), 'message' => "\"{$tur->ad}\" gelir türü eklendi."], 201);
    }

    public function update(Request $request, GelirTuru $tur): JsonResponse
    {
        Gate::authorize('hane-kaydi', $tur);
        $tur->update(['ad' => $this->ad($request, $tur)]);

        return response()->json(['tur' => $tur->ozet(), 'message' => 'Gelir türü kaydedildi.']);
    }

    public function destroy(GelirTuru $tur): JsonResponse
    {
        Gate::authorize('hane-kaydi', $tur);
        $gelir = Gelir::where('gelir_turu_id', $tur->id)->count();
        $kaynak = GelirKaynagi::where('gelir_turu_id', $tur->id)->count();
        if ($gelir + $kaynak > 0) {
            $kullanilan = implode(' ve ', array_filter([$gelir ? "{$gelir} gelir" : null, $kaynak ? "{$kaynak} gelir kaynağı" : null]));
            throw ValidationException::withMessages(['tur' => "\"{$tur->ad}\" türünde {$kullanilan} var; silmeden önce onları başka türe alın."]);
        }
        $tur->delete();

        return response()->json(['message' => "\"{$tur->ad}\" gelir türü silindi."]);
    }

    private function ad(Request $request, ?GelirTuru $tur): string
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'min:2', 'max:40', Rule::unique('gelir_turleri', 'ad')->where('hane_id', $request->user()->hane_id)->ignore($tur?->id)],
        ], ['ad.unique' => 'Bu gelir türü zaten var.'], ['ad' => 'gelir türü']);

        return trim($veri['ad']);
    }
}
