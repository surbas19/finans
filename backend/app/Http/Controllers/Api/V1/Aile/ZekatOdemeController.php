<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\ZekatOdemesi;
use App\Models\ZekatYili;
use App\Services\Zekat\ZekatDonemleri;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Verilen zekât. Bir zekât yılına (kesinleşmiş) bağlanabilir ya da bağsız kalabilir. Tarih bugünden ileri olamaz.
 * Alıcı serbest ad: IBAN ya da hesap numarası alınmaz.
 */
class ZekatOdemeController extends Controller
{
    public function __construct(private ZekatDonemleri $donemler) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'odemeler' => ZekatOdemesi::hanenin($request->user())->orderByDesc('tarih')->orderByDesc('id')->get()
                ->map(fn (ZekatOdemesi $o): array => $o->ozet())->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->yilaBagla($request, $this->dogrula($request));
        $odeme = ZekatOdemesi::create([...$veri, 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json(['odeme' => $odeme->ozet(), 'message' => 'Verilen zekât kaydedildi.'], 201);
    }

    public function update(Request $request, ZekatOdemesi $odeme): JsonResponse
    {
        Gate::authorize('hane-kaydi', $odeme);
        $odeme->update($this->yilaBagla($request, $this->dogrula($request)));

        return response()->json(['odeme' => $odeme->ozet(), 'message' => 'Ödeme kaydedildi.']);
    }

    public function destroy(ZekatOdemesi $odeme): JsonResponse
    {
        Gate::authorize('hane-kaydi', $odeme);
        $odeme->delete();

        return response()->json(['message' => 'Ödeme silindi.']);
    }

    /**
     * Yıl seçilmemişse ödeme tarihini kapsayan (Ramazan'dan Ramazan'a) KESİNLEŞMİŞ zekât yılına bağlanır; yoksa bağsız kalır.
     *
     * @param  array<string, mixed>  $veri
     * @return array<string, mixed>
     */
    private function yilaBagla(Request $request, array $veri): array
    {
        if (empty($veri['zekat_yili_id'])) {
            $veri['zekat_yili_id'] = $this->donemler->kayitBul((int) $request->user()->hane_id, Carbon::parse($veri['tarih']))?->id;
        }

        return $veri;
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'zekat_yili_id' => ['nullable', 'integer', Rule::exists('zekat_yillari', 'id')->where('hane_id', $haneId)],
            'uye_id' => ['nullable', 'integer', Rule::exists('hane_uyeleri', 'id')->where('hane_id', $haneId)],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'alici' => ['nullable', 'string', 'max:120'],
            'not' => ['nullable', 'string', 'max:255'],
        ], ['tarih.before_or_equal' => 'İleri tarihli ödeme girilemez.'], ['tarih' => 'tarih', 'tutar' => 'tutar', 'alici' => 'alıcı']);

        if (! empty($veri['zekat_yili_id']) && ! ZekatYili::whereKey($veri['zekat_yili_id'])->where('durum', ZekatYili::KESINLESTI)->exists()) {
            throw ValidationException::withMessages(['zekat_yili_id' => 'Ödeme yalnızca kesinleşmiş zekât yılına bağlanır.']);
        }
        if (isset($veri['alici'])) {
            $veri['alici'] = trim($veri['alici']) ?: null;
        }

        return $veri;
    }
}
