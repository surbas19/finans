<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\EvNakdiHareketi;
use App\Services\Aile\EvNakdi;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ev nakdi kasası: bakiye ve elle para ekleme / çıkarma. Ev harcamaları giderden gelir (`EvNakdi`).
 */
class EvNakdiController extends Controller
{
    public function index(Request $request, EvNakdi $evNakdi): JsonResponse
    {
        return response()->json($evNakdi->ozet((int) $request->user()->hane_id));
    }

    public function store(Request $request, EvNakdi $evNakdi): JsonResponse
    {
        $veri = $request->validate([
            'tur' => ['required', 'in:'.EvNakdiHareketi::EKLEME.','.EvNakdiHareketi::CIKARMA],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:500'],
        ], [], ['tur' => 'tür', 'tutar' => 'tutar', 'tarih' => 'tarih', 'not' => 'not']);
        $kurus = Kurus::kurus($veri['tutar']);
        $bakiye = $evNakdi->bakiyeKurus((int) $request->user()->hane_id);
        if ($veri['tur'] === EvNakdiHareketi::CIKARMA && $kurus > $bakiye) {
            throw ValidationException::withMessages(['tutar' => 'Ev nakdinde '.number_format(max(0, $bakiye) / 100, 2, ',', '.').' ₺ var; daha fazlası çıkarılamaz.']);
        }
        $veri['tutar'] = Kurus::decimal($kurus);

        EvNakdiHareketi::create([...$veri, 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json([...$evNakdi->ozet((int) $request->user()->hane_id), 'message' => 'Ev nakdi güncellendi.'], 201);
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
