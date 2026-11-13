<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\HaneUyesi;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hane ve üyeleri. Girişi olan üyeyi (kullanıcıyı) yönetici bağlar; girişsiz üyeyi (ör. çocuk) hane ekler.
 */
class HaneController extends Controller
{
    public function __construct(private HaneYonetimi $haneler) {}

    public function goster(Request $request): JsonResponse
    {
        $user = $request->user();
        $hane = $this->haneler->hazirla($user);

        return response()->json([
            'hane' => ['id' => $hane->id, 'ad' => $hane->ad],
            'uyeler' => $hane->uyeler()->get()->map(fn (HaneUyesi $u): array => $u->ozet()),
            'ben_uye_id' => HaneUyesi::where('user_id', $user->id)->value('id'),
        ]);
    }

    public function guncelle(Request $request): JsonResponse
    {
        $veri = $request->validate(['ad' => ['required', 'string', 'max:60']], [], ['ad' => 'hane adı']);
        $hane = $this->haneler->hazirla($request->user());
        $hane->update(['ad' => trim($veri['ad'])]);

        return response()->json(['hane' => ['id' => $hane->id, 'ad' => $hane->ad], 'message' => 'Hane adı kaydedildi.']);
    }

    public function uyeEkle(Request $request): JsonResponse
    {
        $veri = $this->uyeDogrula($request, null);
        $haneId = (int) $request->user()->hane_id;
        $uye = HaneUyesi::create([
            ...$veri,
            'hane_id' => $haneId,
            'sira' => (int) HaneUyesi::where('hane_id', $haneId)->max('sira') + 1,
            'aktif' => true,
        ]);

        return response()->json(['uye' => $uye->ozet(), 'message' => 'Üye eklendi.'], 201);
    }

    public function uyeGuncelle(Request $request, HaneUyesi $uye): JsonResponse
    {
        Gate::authorize('hane-kaydi', $uye);
        $veri = $this->uyeDogrula($request, $uye);
        $veri += $request->validate(['aktif' => ['sometimes', 'boolean']]);
        $uye->update($veri);

        return response()->json(['uye' => $uye->ozet(), 'message' => 'Üye kaydedildi.']);
    }

    /**
     * Kaydı (gelir, kart…) olan üye silinmez, pasifleşir; girişi olan üyeyi yönetici başka haneye taşır.
     */
    public function uyeSil(HaneUyesi $uye): JsonResponse
    {
        Gate::authorize('hane-kaydi', $uye);
        if ($uye->user_id !== null) {
            throw ValidationException::withMessages(['uye' => 'Girişi olan kişi silinemez; yönetici onu başka bir haneye taşıyabilir.']);
        }
        if ($this->haneler->uyeninKaydiVarMi($uye)) {
            $uye->update(['aktif' => false]);

            return response()->json(['uye' => $uye->ozet(), 'message' => 'Üyenin kayıtları olduğu için silinmedi, pasif yapıldı.']);
        }
        $uye->delete();

        return response()->json(['message' => 'Üye silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function uyeDogrula(Request $request, ?HaneUyesi $uye): array
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:60', Rule::unique('hane_uyeleri', 'ad')->where('hane_id', $request->user()->hane_id)->ignore($uye?->id)],
            'renk' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9#-]+$/i'],
        ], ['ad.unique' => 'Hanede bu adla bir üye zaten var.'], ['ad' => 'ad']);
        $veri['ad'] = trim($veri['ad']);

        return $veri;
    }
}
