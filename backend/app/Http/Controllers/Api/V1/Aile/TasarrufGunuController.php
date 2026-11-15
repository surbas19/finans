<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\TasarrufGunu;
use App\Models\Varlik;
use App\Services\Aile\GunTakvimi;
use App\Support\Donem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Altın / döviz günü şablonları. Silinen günün geçmiş alımları (tasarruf kayıtları) kalır, gün bağı kopar.
 */
class TasarrufGunuController extends Controller
{
    public function index(Request $request, GunTakvimi $takvim): JsonResponse
    {
        $veri = $request->validate(['donem' => ['nullable', 'regex:'.Donem::DESEN]]);
        $user = $request->user();

        return response()->json([
            'gunler' => TasarrufGunu::hanenin($user)->with(['varlik', 'hesap'])->orderBy('ay_gunu')->orderBy('id')->get()
                ->map(fn (TasarrufGunu $g): array => $g->ozet()),
            'ay' => $takvim->ayinGunleri((int) $user->hane_id, $veri['donem'] ?? Donem::buAy()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $gun = TasarrufGunu::create([...$this->dogrula($request), 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json(['gun' => $gun->load(['varlik', 'hesap'])->ozet(), 'message' => 'Gün eklendi.'], 201);
    }

    public function update(Request $request, TasarrufGunu $gun): JsonResponse
    {
        Gate::authorize('hane-kaydi', $gun);
        $gun->update($this->dogrula($request));

        return response()->json(['gun' => $gun->load(['varlik', 'hesap'])->ozet(), 'message' => 'Gün kaydedildi.']);
    }

    public function destroy(TasarrufGunu $gun): JsonResponse
    {
        Gate::authorize('hane-kaydi', $gun);
        $gun->delete();

        return response()->json(['message' => 'Gün silindi; yapılmış alımlar tasarruf olarak duruyor.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request): array
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:80'],
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
            'varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')],
            'miktar' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'ay_gunu' => ['required', 'integer', 'between:1,28'],
            'baslangic' => ['required', 'date'],
            'ay_sayisi' => ['nullable', 'integer', 'between:1,240'],
            'bizde_ay' => ['nullable', 'regex:'.Donem::DESEN],
            'aktif' => ['sometimes', 'boolean'],
        ], [], ['ad' => 'gün adı', 'hesap_id' => 'hesap', 'varlik_id' => 'varlık', 'miktar' => 'miktar', 'ay_gunu' => 'ayın günü', 'baslangic' => 'başlangıç', 'ay_sayisi' => 'ay sayısı', 'bizde_ay' => 'bizde olan ay']);
        if (Varlik::findOrFail((int) $veri['varlik_id'])->tur === VarlikTuru::Bes) {
            throw ValidationException::withMessages(['varlik_id' => 'Bireysel emeklilik gün olarak tanımlanmaz.']);
        }
        $veri['ad'] = trim($veri['ad']);
        // Sıranın bizde olduğu ay, günün süresi içinde olmalı
        if (! empty($veri['bizde_ay'])) {
            $fark = Donem::fark(Donem::tarihten(Carbon::parse($veri['baslangic'])), $veri['bizde_ay']);
            if ($fark < 0 || (! empty($veri['ay_sayisi']) && $fark >= (int) $veri['ay_sayisi'])) {
                throw ValidationException::withMessages(['bizde_ay' => 'Bizde olan ay, günün ilk alım ayı ile süresi arasında olmalı.']);
            }
        }

        return $veri;
    }
}
