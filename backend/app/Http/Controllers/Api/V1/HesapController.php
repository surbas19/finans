<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HesapTuru;
use App\Http\Controllers\Controller;
use App\Models\Hesap;
use App\Models\Islem;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HesapController extends Controller
{
    public function __construct(private PortfoyHesabi $portfoy) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'hesaplar' => $this->portfoy->hesapla($request->user())['hesaplar'],
            'turler' => collect(HesapTuru::cases())->map(fn (HesapTuru $t): array => ['deger' => $t->value, 'etiket' => $t->etiket()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request, null);
        // Hanedeki hesaplar birlikte dizilir: yeni hesap hepsinin sonuna
        $hesap = $request->user()->hesaplar()->create([
            ...$veri,
            'sira' => (int) Hesap::erisilebilir($request->user())->max('sira') + 1,
        ]);

        return response()->json(['hesap' => $hesap->ozet(), 'message' => 'Hesap eklendi.'], 201);
    }

    /**
     * Hesabın varlıkları (pozisyonlar) ve işlem geçmişi.
     */
    public function show(Request $request, Hesap $hesap): JsonResponse
    {
        Gate::authorize('view', $hesap);
        $portfoy = $this->portfoy->hesapla($request->user(), $hesap->id);

        return response()->json([
            'hesap' => $portfoy['hesaplar'][0],
            'pozisyonlar' => $portfoy['pozisyonlar'],
            'dagilim' => $portfoy['dagilim'],
            'islemler' => Islem::where('hesap_id', $hesap->id)->with('varlik')
                ->orderByDesc('tarih')->orderByDesc('id')->get()
                ->map(fn (Islem $i): array => [...$i->ozet(), 'varlik' => $i->varlik->ozet()]),
        ]);
    }

    public function update(Request $request, Hesap $hesap): JsonResponse
    {
        Gate::authorize('update', $hesap);
        $hesap->update($this->dogrula($request, $hesap));

        return response()->json(['hesap' => $hesap->ozet(), 'message' => 'Hesap güncellendi.']);
    }

    /**
     * Hesap işlemleriyle birlikte silinir (arayüz onay ister).
     */
    public function destroy(Hesap $hesap): JsonResponse
    {
        Gate::authorize('delete', $hesap);
        $hesap->delete();

        return response()->json(['message' => 'Hesap silindi.']);
    }

    public function sirala(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer', 'distinct', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
        ]);
        foreach ($veri['ids'] as $sira => $id) {
            Hesap::whereKey($id)->erisilebilir($request->user())->update(['sira' => $sira + 1]);
        }

        return response()->json(['message' => 'Sıra kaydedildi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?Hesap $hesap): array
    {
        return $request->validate([
            'ad' => ['required', 'string', 'max:60', Rule::unique('hesaplar', 'ad')->where('user_id', $request->user()->id)->ignore($hesap?->id)],
            'tur' => ['required', Rule::enum(HesapTuru::class)],
            'renk' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9#-]+$/i'],
            'not' => ['nullable', 'string', 'max:500'],
        ], ['ad.unique' => 'Bu adla bir hesabınız zaten var.'], ['ad' => 'hesap adı', 'tur' => 'hesap türü']);
    }
}
