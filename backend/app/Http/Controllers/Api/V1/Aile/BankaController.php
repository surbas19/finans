<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Banka;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bankalar (yalnızca ad ve renk; banka / hesap numarası tutulmaz).
 */
class BankaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'bankalar' => Banka::hanenin($request->user())->orderBy('ad')->get()->map(fn (Banka $b): array => $b->ozet()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $banka = Banka::create([...$this->dogrula($request, null), 'hane_id' => $request->user()->hane_id]);

        return response()->json(['banka' => $banka->ozet(), 'message' => 'Banka eklendi.'], 201);
    }

    public function update(Request $request, Banka $banka): JsonResponse
    {
        Gate::authorize('hane-kaydi', $banka);
        $banka->update($this->dogrula($request, $banka));

        return response()->json(['banka' => $banka->ozet(), 'message' => 'Banka kaydedildi.']);
    }

    public function destroy(Banka $banka): JsonResponse
    {
        Gate::authorize('hane-kaydi', $banka);
        if ($banka->kartlar()->exists()) {
            throw ValidationException::withMessages(['banka' => 'Bu bankanın kartları var; önce kartları silin.']);
        }
        $banka->delete();

        return response()->json(['message' => 'Banka silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?Banka $banka): array
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:60', Rule::unique('bankalar', 'ad')->where('hane_id', $request->user()->hane_id)->ignore($banka?->id)],
            'renk' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9#-]+$/i'],
        ], ['ad.unique' => 'Bu banka zaten ekli.'], ['ad' => 'banka adı']);
        $veri['ad'] = trim($veri['ad']);

        return $veri;
    }
}
