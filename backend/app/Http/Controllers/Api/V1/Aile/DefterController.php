<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\DefterDegisikligi;
use App\Services\Aile\DefterKapanisi;
use App\Support\Donem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Defter: ayın kategorili kayıtları, temettü, borç / alacak ve kalemlerin adet + değer değişimi. Salt okunur: yazma ucu
 * yoktur. Kapanmış ay mühürlü görüntüden gelir; kapanıştan sonraki değişiklikler ayrı listelenir, ay toplamlarına katılmaz.
 */
class DefterController extends Controller
{
    public function goster(Request $request, DefterKapanisi $kapanis): JsonResponse
    {
        $donem = (string) $request->query('donem', Donem::buAy());
        if (! Donem::gecerli($donem) || $donem > Donem::buAy()) {
            throw ValidationException::withMessages(['donem' => 'Dönem geçersiz ya da henüz başlamadı.']);
        }
        $haneId = (int) $request->user()->hane_id;
        $defter = $kapanis->oku($haneId, $donem);
        $degisiklikler = DefterDegisikligi::where('hane_id', $haneId)->where('donem', $donem)->orderBy('yapildi_at')->orderBy('id')->get()
            ->map(fn (DefterDegisikligi $d): array => [
                'id' => $d->id,
                'tip' => $d->tip,
                'tur' => $d->tur,
                'baslik' => $d->baslik,
                'kayit_tarihi' => $d->kayit_tarihi?->toDateString(),
                'eski_tutar' => $d->eski_tutar !== null ? (float) $d->eski_tutar : null,
                'yeni_tutar' => $d->yeni_tutar !== null ? (float) $d->yeni_tutar : null,
                'yapan_ad' => $d->yapan_ad,
                'yapildi_at' => $d->yapildi_at->toIso8601String(),
            ])->values();

        return response()->json([
            'donem' => $donem,
            'kapali' => $defter['kapali'],
            'muhurlendi_at' => $defter['muhurlendi'],
            'ay_sonu' => Donem::sonGun($donem)->toDateString(),
            'defter' => $defter['icerik'],
            'degisiklikler' => $degisiklikler,
        ]);
    }
}
