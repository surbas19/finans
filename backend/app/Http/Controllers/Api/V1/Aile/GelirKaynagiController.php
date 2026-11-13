<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\GelirDuzeni;
use App\Enums\GelirSikligi;
use App\Enums\GelirTuru;
use App\Http\Controllers\Controller;
use App\Models\GelirKaynagi;
use App\Services\Aile\DovizKuru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Gelir kaynakları: kimin, hangi tür geliri, düzenliyse ne sıklıkla. Silinen kaynağın gelirleri kalır (kaynaksız).
 */
class GelirKaynagiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'kaynaklar' => GelirKaynagi::hanenin($request->user())->orderBy('uye_id')->orderBy('ad')->get()
                ->map(fn (GelirKaynagi $k): array => $k->ozet()),
        ]);
    }

    public function store(Request $request, DovizKuru $kur): JsonResponse
    {
        $kaynak = GelirKaynagi::create([...$this->dogrula($request, $kur), 'hane_id' => $request->user()->hane_id]);

        return response()->json(['kaynak' => $kaynak->ozet(), 'message' => 'Gelir kaynağı eklendi.'], 201);
    }

    public function update(Request $request, GelirKaynagi $kaynak, DovizKuru $kur): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kaynak);
        $kaynak->update($this->dogrula($request, $kur));

        return response()->json(['kaynak' => $kaynak->ozet(), 'message' => 'Gelir kaynağı kaydedildi.']);
    }

    public function destroy(GelirKaynagi $kaynak): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kaynak);
        $kaynak->delete();

        return response()->json(['message' => 'Gelir kaynağı silindi; girilmiş gelirler duruyor.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, DovizKuru $kur): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'uye_id' => ['required', 'integer', Rule::exists('hane_uyeleri', 'id')->where('hane_id', $haneId)],
            'tur' => ['required', Rule::enum(GelirTuru::class)],
            'ad' => ['required', 'string', 'max:80'],
            'duzen' => ['required', Rule::enum(GelirDuzeni::class)],
            'siklik' => ['nullable', 'required_if:duzen,duzenli', Rule::enum(GelirSikligi::class)],
            'beklenen_tutar' => ['nullable', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'para_birimi' => ['sometimes', 'string', Rule::in(['TRY', ...$kur->kodlar()])],
            'gun' => ['nullable', 'integer', 'between:1,31'],
            'baslangic' => ['nullable', 'date'],
            'bitis' => ['nullable', 'date', 'after_or_equal:baslangic'],
            'aktif' => ['sometimes', 'boolean'],
            'not' => ['nullable', 'string', 'max:500'],
        ], ['siklik.required_if' => 'Düzenli gelirde sıklık gerekir.'], [
            'uye_id' => 'kişi', 'tur' => 'gelir türü', 'ad' => 'kaynak', 'duzen' => 'düzen', 'siklik' => 'sıklık',
            'beklenen_tutar' => 'beklenen tutar', 'gun' => 'gün', 'baslangic' => 'başlangıç', 'bitis' => 'bitiş',
        ]);
        $veri['ad'] = trim($veri['ad']);
        if ($veri['duzen'] !== GelirDuzeni::Duzenli->value) {
            $veri['siklik'] = null;
        }

        return $veri;
    }
}
