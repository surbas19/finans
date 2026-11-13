<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\HarcamaKategorisi;
use App\Models\KategoriKurali;
use App\Support\Metin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Harcama kategorileri ve kuralları (açıklamada geçen kelime → kategori). Silinen kategorinin işlemleri kategorisiz kalır.
 */
class KategoriController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'kategoriler' => HarcamaKategorisi::hanenin($request->user())->with('kurallar')->orderBy('sira')->orderBy('ad')->get()
                ->map(fn (HarcamaKategorisi $k): array => [
                    ...$k->ozet(),
                    'kurallar' => $k->kurallar->map(fn (KategoriKurali $r): array => ['id' => $r->id, 'desen' => $r->desen])->values(),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $haneId = $request->user()->hane_id;
        $kategori = HarcamaKategorisi::create([
            ...$this->dogrula($request, null),
            'hane_id' => $haneId,
            'sira' => (int) HarcamaKategorisi::where('hane_id', $haneId)->max('sira') + 1,
        ]);

        return response()->json(['kategori' => $kategori->ozet(), 'message' => 'Kategori eklendi.'], 201);
    }

    public function update(Request $request, HarcamaKategorisi $kategori): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kategori);
        $kategori->update($this->dogrula($request, $kategori));

        return response()->json(['kategori' => $kategori->ozet(), 'message' => 'Kategori kaydedildi.']);
    }

    public function destroy(HarcamaKategorisi $kategori): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kategori);
        if ($kategori->fatura) {
            throw ValidationException::withMessages(['kategori' => 'Faturalar kategorisi silinemez (fatura ödemeleri buraya yazılır).']);
        }
        $kategori->delete();

        return response()->json(['message' => 'Kategori silindi; işlemleri kategorisiz kaldı.']);
    }

    public function kuralEkle(Request $request, HarcamaKategorisi $kategori): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kategori);
        $veri = $request->validate(['desen' => ['required', 'string', 'min:2', 'max:60']], [], ['desen' => 'kelime']);
        $desen = Metin::sade($veri['desen']);
        if (KategoriKurali::where('hane_id', $kategori->hane_id)->where('desen', $desen)->exists()) {
            throw ValidationException::withMessages(['desen' => "\"{$desen}\" zaten bir kategoriye bağlı."]);
        }
        $kural = KategoriKurali::create(['hane_id' => $kategori->hane_id, 'kategori_id' => $kategori->id, 'desen' => $desen]);

        return response()->json(['kural' => ['id' => $kural->id, 'desen' => $kural->desen], 'message' => 'Kural eklendi.'], 201);
    }

    public function kuralSil(KategoriKurali $kural): JsonResponse
    {
        Gate::authorize('hane-kaydi', $kural);
        $kural->delete();

        return response()->json(['message' => 'Kural silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?HarcamaKategorisi $kategori): array
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:40', Rule::unique('harcama_kategorileri', 'ad')->where('hane_id', $request->user()->hane_id)->ignore($kategori?->id)],
            'ikon' => ['nullable', 'string', 'max:40', 'regex:/^pi pi-[a-z-]+$/'],
            'renk' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9#-]+$/i'],
        ], ['ad.unique' => 'Bu kategori zaten var.'], ['ad' => 'kategori adı']);
        $veri['ad'] = trim($veri['ad']);

        return $veri;
    }
}
