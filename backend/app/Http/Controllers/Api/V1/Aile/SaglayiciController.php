<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\FaturaSaglayicisi;
use App\Models\FaturaTuru;
use App\Models\SaglayiciDeseni;
use App\Support\Metin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fatura türleri (sistem: elektrik, su, doğalgaz + hanenin ekledikleri) ve sağlayıcılar: kurum, ekstrede görünen
 * yazımları (desen), otomatik kayıt ayarı.
 */
class SaglayiciController extends Controller
{
    public function turler(Request $request): JsonResponse
    {
        return response()->json([
            'turler' => FaturaTuru::haneIcin($request->user()->hane_id)->orderByRaw('hane_id is not null')->orderBy('sira')->orderBy('ad')->get()
                ->map(fn (FaturaTuru $t): array => $t->ozet()),
        ]);
    }

    public function turEkle(Request $request): JsonResponse
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:40', function (string $alan, mixed $deger, \Closure $hata) use ($haneId): void {
                if (is_string($deger) && FaturaTuru::haneIcin($haneId)->whereRaw('LOWER(ad) = ?', [mb_strtolower(trim($deger))])->exists()) {
                    $hata('Bu fatura türü zaten var.');
                }
            }],
            'ikon' => ['nullable', 'string', 'max:40', 'regex:/^pi pi-[a-z-]+$/'],
        ], [], ['ad' => 'tür adı']);
        $tur = FaturaTuru::create(['hane_id' => $haneId, 'ad' => trim($veri['ad']), 'ikon' => $veri['ikon'] ?? 'pi pi-file', 'sira' => 100]);

        return response()->json(['tur' => $tur->ozet(), 'message' => 'Fatura türü eklendi.'], 201);
    }

    public function turSil(Request $request, FaturaTuru $tur): JsonResponse
    {
        if ($tur->hane_id === null || $tur->hane_id !== $request->user()->hane_id) {
            abort(403);
        }
        if (FaturaSaglayicisi::where('fatura_turu_id', $tur->id)->exists()) {
            throw ValidationException::withMessages(['tur' => 'Bu türde sağlayıcı var; önce sağlayıcıları silin.']);
        }
        $tur->delete();

        return response()->json(['message' => 'Fatura türü silindi.']);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'saglayicilar' => FaturaSaglayicisi::hanenin($request->user())->with('desenler')->orderBy('ad')->get()
                ->map(fn (FaturaSaglayicisi $s): array => $s->ozet()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request, null);
        $saglayici = FaturaSaglayicisi::create([...$veri, 'hane_id' => $request->user()->hane_id]);
        // Sağlayıcının adı da bir desendir (ayrıca eklenmez); verilen ek yazımlar desen olur
        foreach ($request->input('desenler', []) as $desen) {
            if (is_string($desen) && mb_strlen(trim($desen)) >= 2) {
                $this->desenYaz($saglayici, $desen);
            }
        }

        return response()->json(['saglayici' => $saglayici->load('desenler')->ozet(), 'message' => 'Sağlayıcı eklendi.'], 201);
    }

    public function update(Request $request, FaturaSaglayicisi $saglayici): JsonResponse
    {
        Gate::authorize('hane-kaydi', $saglayici);
        $saglayici->update($this->dogrula($request, $saglayici));

        return response()->json(['saglayici' => $saglayici->load('desenler')->ozet(), 'message' => 'Sağlayıcı kaydedildi.']);
    }

    public function destroy(FaturaSaglayicisi $saglayici): JsonResponse
    {
        Gate::authorize('hane-kaydi', $saglayici);
        if (Fatura::where('saglayici_id', $saglayici->id)->exists()) {
            throw ValidationException::withMessages(['saglayici' => 'Bu sağlayıcının faturaları var; silmek yerine pasif yapın.']);
        }
        $saglayici->delete();

        return response()->json(['message' => 'Sağlayıcı silindi.']);
    }

    public function desenEkle(Request $request, FaturaSaglayicisi $saglayici): JsonResponse
    {
        Gate::authorize('hane-kaydi', $saglayici);
        $veri = $request->validate(['desen' => ['required', 'string', 'min:2', 'max:60']], [], ['desen' => 'yazım']);
        $desen = $this->desenYaz($saglayici, $veri['desen']);

        return response()->json(['desen' => ['id' => $desen->id, 'desen' => $desen->desen], 'message' => 'Yazım eklendi.'], 201);
    }

    public function desenSil(SaglayiciDeseni $desen): JsonResponse
    {
        Gate::authorize('hane-kaydi', $desen);
        $desen->delete();

        return response()->json(['message' => 'Yazım silindi.']);
    }

    private function desenYaz(FaturaSaglayicisi $saglayici, string $metin): SaglayiciDeseni
    {
        $desen = Metin::sade($metin);
        $varolan = SaglayiciDeseni::where('hane_id', $saglayici->hane_id)->where('desen', $desen)->first();
        if ($varolan) {
            $sahibi = FaturaSaglayicisi::find($varolan->saglayici_id);
            throw ValidationException::withMessages(['desen' => "\"{$desen}\" zaten {$sahibi?->ad} sağlayıcısına bağlı."]);
        }

        return SaglayiciDeseni::create(['hane_id' => $saglayici->hane_id, 'saglayici_id' => $saglayici->id, 'desen' => $desen]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?FaturaSaglayicisi $saglayici): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:60', Rule::unique('fatura_saglayicilari', 'ad')->where('hane_id', $haneId)->ignore($saglayici?->id)],
            'fatura_turu_id' => ['required', 'integer', Rule::exists('fatura_turleri', 'id')->where(fn ($q) => $q->whereNull('hane_id')->orWhere('hane_id', $haneId))],
            'otomatik_kayit' => ['sometimes', 'boolean'],
            'aktif' => ['sometimes', 'boolean'],
            'desenler' => ['sometimes', 'array', 'max:20'],
            'desenler.*' => ['string', 'max:60'],
        ], ['ad.unique' => 'Bu sağlayıcı zaten var.'], ['ad' => 'sağlayıcı adı', 'fatura_turu_id' => 'fatura türü']);
        unset($veri['desenler']);
        $veri['ad'] = trim($veri['ad']);

        return $veri;
    }
}
