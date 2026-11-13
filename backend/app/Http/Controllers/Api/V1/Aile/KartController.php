<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Ekstre;
use App\Models\Kart;
use App\Models\KartIslemi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kredi kartları: banka, görünen ad, sahibi, kesim ve son ödeme günü. Kart numarası / CVV istenmez, tutulmaz.
 */
class KartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'kartlar' => Kart::hanenin($request->user())->orderBy('banka_id')->orderBy('ad')->get()->map(fn (Kart $k): array => $k->ozet()),
        ]);
    }

    /**
     * Kartın ekstreleri (yeniden eskiye); işlemler ayrı uçtan süzgeçle gelir.
     */
    public function show(Kart $kart): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kart);

        return response()->json([
            'kart' => $kart->ozet(),
            'ekstreler' => $kart->ekstreler()->withCount('islemler')->orderByDesc('kesim_tarihi')->orderByDesc('id')->get()
                ->map(fn (Ekstre $e): array => [...$e->ozet(), 'satir_sayisi' => $e->islemler_count]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $kart = Kart::create([...$this->dogrula($request, null), 'hane_id' => $request->user()->hane_id]);

        return response()->json(['kart' => $kart->ozet(), 'message' => 'Kart eklendi.'], 201);
    }

    public function update(Request $request, Kart $kart): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kart);
        $kart->update($this->dogrula($request, $kart));

        return response()->json(['kart' => $kart->ozet(), 'message' => 'Kart kaydedildi.']);
    }

    public function destroy(Kart $kart): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kart);
        if ($kart->ekstreler()->exists() || KartIslemi::where('kart_id', $kart->id)->exists()) {
            throw ValidationException::withMessages(['kart' => 'Kartın ekstreleri ya da işlemleri var; silmek yerine pasif yapın.']);
        }
        $kart->delete();

        return response()->json(['message' => 'Kart silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?Kart $kart): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'banka_id' => ['required', 'integer', Rule::exists('bankalar', 'id')->where('hane_id', $haneId)],
            'sahip_uye_id' => ['required', 'integer', Rule::exists('hane_uyeleri', 'id')->where('hane_id', $haneId)],
            'ad' => ['required', 'string', 'max:60', Rule::unique('kartlar', 'ad')->where('hane_id', $haneId)->ignore($kart?->id)],
            'kesim_gunu' => ['nullable', 'integer', 'between:1,31'],
            'son_odeme_gunu' => ['nullable', 'integer', 'between:1,31'],
            'renk' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9#-]+$/i'],
            'aktif' => ['sometimes', 'boolean'],
        ], ['ad.unique' => 'Bu adla bir kart zaten var.'], [
            'banka_id' => 'banka', 'sahip_uye_id' => 'kart sahibi', 'ad' => 'kart adı', 'kesim_gunu' => 'kesim günü', 'son_odeme_gunu' => 'son ödeme günü',
        ]);
        // Kart adında uzun rakam dizisi (kart numarası) kabul edilmez
        if (preg_match('/\d[\d\s-]{10,}\d/', $veri['ad'])) {
            throw ValidationException::withMessages(['ad' => 'Kart numarası yazmayın; yalnızca tanıyacağınız bir ad verin (ör. "Bonus - Ayşe").']);
        }
        $veri['ad'] = trim($veri['ad']);

        return $veri;
    }
}
