<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\EvNakdiHareketi;
use App\Models\Hane;
use App\Services\Aile\EvNakdi;
use App\Services\Aile\NakitKaynaklari;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ev nakdi: bakiye, para ekleme / çıkarma ve ev nakdinin hangi portföy hesabı olduğu (`EvNakdi`). Hesap seçiliyse
 * ekleme / çıkarma o hesaba Nakit alış / satışı olarak yazılır; seçili değilse ayrı kasaya hareket. Ev harcamaları
 * giderden gelir.
 */
class EvNakdiController extends Controller
{
    public function index(Request $request, EvNakdi $evNakdi): JsonResponse
    {
        return response()->json($evNakdi->ozet((int) $request->user()->hane_id));
    }

    public function store(Request $request, EvNakdi $evNakdi, NakitKaynaklari $kaynaklar): JsonResponse
    {
        $veri = $request->validate([
            'tur' => ['required', 'in:'.EvNakdiHareketi::EKLEME.','.EvNakdiHareketi::CIKARMA],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:500'],
        ], [], ['tur' => 'tür', 'tutar' => 'tutar', 'tarih' => 'tarih', 'not' => 'not']);
        $haneId = (int) $request->user()->hane_id;
        $kurus = Kurus::kurus($veri['tutar']);

        $hesap = $evNakdi->evHesabi($haneId);
        if ($hesap) {
            $kaynaklar->evHareketiYaz($request->user(), $hesap, $veri['tur'], $kurus, $veri['tarih'], $veri['not'] ?? null);

            return response()->json([...$evNakdi->ozet($haneId), 'message' => '"'.$hesap->ad.'" hesabının nakdi güncellendi.'], 201);
        }

        $bakiye = $evNakdi->bakiyeKurus($haneId);
        if ($veri['tur'] === EvNakdiHareketi::CIKARMA && $kurus > $bakiye) {
            throw ValidationException::withMessages(['tutar' => 'Ev nakdinde '.number_format(max(0, $bakiye) / 100, 2, ',', '.').' ₺ var; daha fazlası çıkarılamaz.']);
        }
        $veri['tutar'] = Kurus::decimal($kurus);
        EvNakdiHareketi::create([...$veri, 'hane_id' => $haneId, 'olusturan_id' => $request->user()->id]);

        return response()->json([...$evNakdi->ozet($haneId), 'message' => 'Ev nakdi güncellendi.'], 201);
    }

    /**
     * Ev nakdi hangi portföy hesabı (ör. "Ev"): seçilince bakiye o hesabın Nakit (TL)'si olur; `null` ayrı kasaya döner.
     * Yalnız hanenin hesapları seçilebilir.
     */
    public function hesap(Request $request, EvNakdi $evNakdi): JsonResponse
    {
        $veri = $request->validate([
            'hesap_id' => ['present', 'nullable', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
        ], [], ['hesap_id' => 'hesap']);
        $haneId = (int) $request->user()->hane_id;
        Hane::whereKey($haneId)->update(['ev_hesap_id' => $veri['hesap_id']]);
        $ozet = $evNakdi->ozet($haneId);

        return response()->json([
            ...$ozet,
            'message' => $ozet['hesap'] ? 'Ev nakdi artık "'.$ozet['hesap']['ad'].'" hesabının nakdi.' : 'Ev nakdi ayrı kasaya döndü.',
        ]);
    }

    public function destroy(Request $request, EvNakdiHareketi $hareket, EvNakdi $evNakdi): JsonResponse
    {
        Gate::authorize('hane-kaydi', $hareket);
        // Eklenen para silinince bakiye eksiye düşecekse reddedilir (harcamalar o paradan çıkmıştır)
        if ($hareket->tur === EvNakdiHareketi::EKLEME && $evNakdi->bakiyeKurus((int) $request->user()->hane_id) - Kurus::kurus($hareket->tutar) < 0) {
            throw ValidationException::withMessages(['hareket' => 'Bu para ev nakdinden harcanmış; silinirse bakiye eksiye düşer. Önce ilgili harcamayı silin.']);
        }
        $hareket->delete();

        return response()->json([...$evNakdi->ozet((int) $request->user()->hane_id), 'message' => 'Hareket silindi.']);
    }
}
