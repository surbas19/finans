<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\EvNakdiHareketi;
use App\Services\Aile\EvNakdi;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
        $veri['tutar'] = Kurus::decimal(Kurus::kurus($veri['tutar']));

        EvNakdiHareketi::create([...$veri, 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json([...$evNakdi->ozet((int) $request->user()->hane_id), 'message' => 'Ev nakdi güncellendi.'], 201);
    }

    public function destroy(Request $request, EvNakdiHareketi $hareket, EvNakdi $evNakdi): JsonResponse
    {
        Gate::authorize('hane-kaydi', $hareket);
        $hareket->delete();

        return response()->json([...$evNakdi->ozet((int) $request->user()->hane_id), 'message' => 'Hareket silindi.']);
    }
}
