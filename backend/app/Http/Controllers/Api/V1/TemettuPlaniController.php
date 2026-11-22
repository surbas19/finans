<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\Hesap;
use App\Models\TemettuPlani;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\TemettuPlanlari;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Önceden planlanan temettüler (Portföy → Temettü) ve Özet şeridi. Hesabı görebilen herkes görür; yatınca gerçek temettü
 * kaydı olur (`TemettuPlanlari::yatti`).
 */
class TemettuPlaniController extends Controller
{
    public function __construct(private TemettuPlanlari $planlar, private PortfoyHesabi $portfoy) {}

    public function index(Request $request): JsonResponse
    {
        $liste = TemettuPlani::erisilebilir($request->user())->with(['hesap', 'varlik.fiyat'])->orderBy('tarih')->orderBy('id')->get();
        $bekleyen = $liste->where('durum', TemettuPlani::BEKLIYOR)->map(fn (TemettuPlani $p): array => $this->planlar->ozet($p))->values();
        $yatan = $liste->where('durum', TemettuPlani::YATTI)->sortByDesc('yatti_at')->take(20)->map(fn (TemettuPlani $p): array => $this->planlar->ozet($p))->values();

        return response()->json([
            'bekleyenler' => $bekleyen,
            'yatanlar' => $yatan,
            'toplam_net' => round((float) $bekleyen->sum('net'), 2),
            'serit' => $this->planlar->serit($request->user()),
            'hatirlatma_gun' => TemettuPlanlari::HATIRLATMA_GUN,
        ]);
    }

    /** Planlanabilecek (hesap + hisse) listesi: hesapta elde hisse olanlar, lot ve güncel fiyatla */
    public function secenekler(Request $request): JsonResponse
    {
        $sonuc = $this->portfoy->hesapla($request->user());
        $adlar = collect($sonuc['hesaplar'])->pluck('ad', 'id');

        return response()->json(['secenekler' => collect($sonuc['pozisyonlar'])
            ->filter(fn (array $p): bool => ($p['varlik']['tur'] ?? null) === VarlikTuru::Hisse->value && $p['miktar'] > 0 && $p['hesap_id'] !== null)
            ->map(fn (array $p): array => [
                'hesap_id' => $p['hesap_id'], 'hesap_ad' => $adlar[$p['hesap_id']] ?? '', 'varlik_id' => $p['varlik']['id'],
                'kod' => $p['varlik']['kod'], 'ad' => $p['varlik']['ad'], 'lot' => $p['miktar'], 'fiyat_tl' => $p['fiyat_tl'],
            ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request, true);
        $plan = TemettuPlani::create([...$veri, 'user_id' => $request->user()->id, 'durum' => TemettuPlani::BEKLIYOR]);

        return response()->json(['plan' => $this->planlar->ozet($plan->load(['hesap', 'varlik.fiyat'])), 'message' => 'Temettü planlandı.'], 201);
    }

    public function update(Request $request, TemettuPlani $plan): JsonResponse
    {
        $this->yetki($request, $plan);
        $veri = $this->dogrula($request, false);
        $plan->update($veri);

        return response()->json(['plan' => $this->planlar->ozet($plan->load(['hesap', 'varlik.fiyat'])), 'message' => 'Plan kaydedildi.']);
    }

    public function destroy(Request $request, TemettuPlani $plan): JsonResponse
    {
        $this->yetki($request, $plan);
        $plan->delete();

        return response()->json(['message' => 'Plan silindi.']);
    }

    public function yatti(Request $request, TemettuPlani $plan): JsonResponse
    {
        $this->yetki($request, $plan);
        $veri = $request->validate(['net' => ['nullable', 'numeric', 'gt:0', 'max:1000000000']], [], ['net' => 'net tutar']);
        $plan = $this->planlar->yatti($plan, $request->user(), isset($veri['net']) ? (float) $veri['net'] : null);

        return response()->json(['plan' => $this->planlar->ozet($plan->load(['hesap', 'varlik.fiyat'])), 'message' => 'Temettü kaydedildi; tutar hesaba nakit olarak eklendi.']);
    }

    /** Hesabı göremeyen kullanıcı planı da göremez / değiştiremez */
    private function yetki(Request $request, TemettuPlani $plan): void
    {
        abort_unless(Hesap::erisilebilir($request->user())->whereKey($plan->hesap_id)->exists(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, bool $yeni): array
    {
        $kurallar = [
            'tarih' => ['required', 'date', $yeni ? 'after_or_equal:today' : 'date'],
            'hisse_basi' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'stopaj_yuzde' => ['required', 'numeric', 'min:0', 'max:100'],
            'not' => ['nullable', 'string', 'max:255'],
        ];
        if ($yeni) {
            $kurallar['hesap_id'] = ['required', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())];
            $kurallar['varlik_id'] = ['required', 'integer', Rule::exists('varliklar', 'id')];
        }
        $veri = $request->validate($kurallar, ['tarih.after_or_equal' => 'Önceden planlanan temettünün tarihi bugün ya da sonrası olmalı; geçmişi hisse satırından girin.'],
            ['tarih' => 'tarih', 'hisse_basi' => 'hisse başı tutar', 'stopaj_yuzde' => 'stopaj']);
        if ($yeni) {
            $varlik = Varlik::findOrFail((int) $veri['varlik_id']);
            if ($varlik->tur !== VarlikTuru::Hisse) {
                throw ValidationException::withMessages(['varlik_id' => 'Temettü yalnızca hisse senedi için planlanır.']);
            }
            if ($this->planlar->lot((int) $veri['hesap_id'], $varlik->id, Carbon::parse($veri['tarih'])) <= 0) {
                throw ValidationException::withMessages(['varlik_id' => 'Bu hesapta bu hisse yok; temettü elde hisse varken planlanır.']);
            }
        }

        return $veri;
    }
}
