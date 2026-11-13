<?php

namespace App\Http\Controllers\Api\V1\Yonetim;

use App\Fiyat\FiyatGuncelleyici;
use App\Http\Controllers\Controller;
use App\Models\KaynakDurumu;
use App\Models\Varlik;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Fiyat kaynaklarının durumu ve elle "Şimdi güncelle" (yalnızca yönetici).
 */
class KaynakController extends Controller
{
    public function index(FiyatGuncelleyici $guncelleyici): JsonResponse
    {
        Gate::authorize('yonetici');
        $durumlar = KaynakDurumu::all()->keyBy('kaynak');
        $sayilar = Varlik::where('aktif', true)->selectRaw('kaynak, count(*) as sayi')->groupBy('kaynak')->pluck('sayi', 'kaynak');

        $ad = fn (string $k): string => match ($k) {
            'truncgil' => 'Truncgil (altın, döviz)',
            'yahoo' => 'Yahoo Finance (BIST, ons; gecikmeli)',
            'binance' => 'Binance (kripto)',
            'elle' => 'Elle girilen (fonlar)',
            default => $k,
        };

        return response()->json([
            'kaynaklar' => collect([...array_keys($guncelleyici->kaynaklar()), 'elle'])->map(function (string $k) use ($durumlar, $sayilar, $ad): array {
                $d = $durumlar->get($k);

                return [
                    'kaynak' => $k,
                    'ad' => $ad($k),
                    'varlik_sayisi' => (int) ($sayilar[$k] ?? 0),
                    'son_deneme' => $d?->son_deneme?->toIso8601String(),
                    'son_basari' => $d?->son_basari?->toIso8601String(),
                    'son_hata' => $d?->son_hata,
                    'son_hata_zamani' => $d?->son_hata_zamani?->toIso8601String(),
                    'art_arda_hata' => (int) ($d?->art_arda_hata ?? 0),
                    'guncellenen' => (int) ($d?->guncellenen ?? 0),
                ];
            }),
        ]);
    }

    public function guncelle(FiyatGuncelleyici $guncelleyici): JsonResponse
    {
        Gate::authorize('yonetici');
        $rapor = $guncelleyici->guncelle();
        $guncelleyici->gecmisleriCek();

        return response()->json(['rapor' => $rapor, 'message' => 'Fiyatlar güncellendi.']);
    }
}
