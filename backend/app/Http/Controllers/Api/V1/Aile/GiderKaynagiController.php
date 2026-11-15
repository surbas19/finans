<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\GelirSikligi;
use App\Http\Controllers\Controller;
use App\Models\GiderKaynagi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Düzenli ödemelerin tanımı (eğitim, bakıcı…). Silinen kaynağın giderleri kalır (kaynaksız).
 */
class GiderKaynagiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'kaynaklar' => GiderKaynagi::hanenin($request->user())->orderBy('ad')->get()
                ->map(fn (GiderKaynagi $k): array => $k->ozet()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $kaynak = GiderKaynagi::create([...$this->dogrula($request), 'hane_id' => $request->user()->hane_id]);

        return response()->json(['kaynak' => $kaynak->ozet(), 'message' => 'Düzenli ödeme eklendi.'], 201);
    }

    public function update(Request $request, GiderKaynagi $kaynak): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kaynak);
        $kaynak->update($this->dogrula($request));

        return response()->json(['kaynak' => $kaynak->ozet(), 'message' => 'Düzenli ödeme kaydedildi.']);
    }

    public function destroy(GiderKaynagi $kaynak): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kaynak);
        $kaynak->delete();

        return response()->json(['message' => 'Düzenli ödeme silindi; girilmiş giderler duruyor.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'uye_id' => ['nullable', 'integer', Rule::exists('hane_uyeleri', 'id')->where('hane_id', $haneId)],
            'kategori_id' => ['nullable', 'integer', Rule::exists('harcama_kategorileri', 'id')->where('hane_id', $haneId)],
            'ad' => ['required', 'string', 'max:80'],
            'siklik' => ['required', Rule::enum(GelirSikligi::class)],
            'beklenen_tutar' => ['nullable', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'gun' => ['nullable', 'integer', 'between:1,31'],
            'baslangic' => ['nullable', 'date'],
            'bitis' => ['nullable', 'date', 'after_or_equal:baslangic'],
            'aktif' => ['sometimes', 'boolean'],
            'not' => ['nullable', 'string', 'max:500'],
        ], [], [
            'uye_id' => 'kişi', 'kategori_id' => 'kategori', 'ad' => 'ödeme', 'siklik' => 'sıklık',
            'beklenen_tutar' => 'beklenen tutar', 'gun' => 'gün', 'baslangic' => 'başlangıç', 'bitis' => 'bitiş',
        ]);
        $veri['ad'] = trim($veri['ad']);

        return $veri;
    }
}
