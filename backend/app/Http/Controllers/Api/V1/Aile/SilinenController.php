<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\SilinenKaydi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Silinenler: silinen kayıtların arşivi (salt okunur; yazma / silme ucu yoktur). Hane kaydı olarak yalnız aynı haneden
 * görülür; hane kurulmadan önce yapılan silmeleri (hane_id boş) yalnız silen görür.
 */
class SilinenController extends Controller
{
    private const SAYFA_BOYUTU = 50;

    /**
     * @return Builder<SilinenKaydi>
     */
    private function sorgu(Request $request): Builder
    {
        $user = $request->user();

        // Hanenin kayıtları + hane kurulmadan önce kullanıcının kendi sildikleri (hane_id boş)
        return SilinenKaydi::query()->where(function (Builder $q) use ($user): void {
            $q->whereNull('hane_id')->where('silen_id', $user->id);
            if ($user->hane_id !== null) {
                $q->orWhere('hane_id', $user->hane_id);
            }
        });
    }

    public function index(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'tur' => ['nullable', Rule::in(array_keys(SilinenKaydi::TURLER))],
            'sayfa' => ['nullable', 'integer', 'min:1'],
        ]);
        $sorgu = $this->sorgu($request)->when($veri['tur'] ?? null, fn (Builder $q, string $tur) => $q->where('tur', $tur));
        $toplam = (clone $sorgu)->count();
        $sayfa = (int) ($veri['sayfa'] ?? 1);
        $kayitlar = $sorgu->orderByDesc('silindi_at')->orderByDesc('id')->forPage($sayfa, self::SAYFA_BOYUTU)->get();

        return response()->json([
            'kayitlar' => $kayitlar->map(fn (SilinenKaydi $k): array => $k->ozet())->values(),
            'toplam' => $toplam,
            'sayfa' => $sayfa,
            'sonraki' => $sayfa * self::SAYFA_BOYUTU < $toplam,
            'turler' => SilinenKaydi::TURLER,
            // Tür çiplerinin sayıları (süzgeçten bağımsız)
            'tur_sayilari' => $this->sorgu($request)->selectRaw('tur, count(*) as sayi')->groupBy('tur')->pluck('sayi', 'tur'),
        ]);
    }

    public function show(Request $request, int $kayit): JsonResponse
    {
        $k = $this->sorgu($request)->whereKey($kayit)->firstOrFail();

        return response()->json(['kayit' => $k->ozet(true)]);
    }
}
