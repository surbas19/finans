<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IzlemeKaydi;
use App\Models\Varlik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kullanıcının izleme listesi (yalnızca kendi kaydı).
 */
class IzlemeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $kayitlar = IzlemeKaydi::where('user_id', $request->user()->id)
            ->with('varlik.fiyat')->orderBy('sira')->orderBy('id')->get();

        return response()->json([
            'varliklar' => $kayitlar->map(fn (IzlemeKaydi $k): array => $k->varlik->ozet())->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $request->validate(['varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')->where('aktif', true)]]);
        $kullanici = $request->user()->id;
        IzlemeKaydi::firstOrCreate(
            ['user_id' => $kullanici, 'varlik_id' => (int) $veri['varlik_id']],
            ['sira' => (int) IzlemeKaydi::where('user_id', $kullanici)->max('sira') + 1],
        );

        return response()->json(['message' => 'İzleme listesine eklendi.'], 201);
    }

    public function destroy(Request $request, Varlik $varlik): JsonResponse
    {
        IzlemeKaydi::where('user_id', $request->user()->id)->where('varlik_id', $varlik->id)->delete();

        return response()->json(['message' => 'İzleme listesinden çıkarıldı.']);
    }

    public function sirala(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'varlik_idleri' => ['required', 'array', 'max:300'],
            'varlik_idleri.*' => ['integer', 'distinct'],
        ]);
        foreach ($veri['varlik_idleri'] as $sira => $id) {
            IzlemeKaydi::where('user_id', $request->user()->id)->where('varlik_id', $id)->update(['sira' => $sira + 1]);
        }

        return response()->json(['message' => 'Sıra kaydedildi.']);
    }
}
