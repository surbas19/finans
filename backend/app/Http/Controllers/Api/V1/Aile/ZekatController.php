<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\ZekatAyari;
use App\Models\ZekatYili;
use App\Services\Zekat\ZekatHesabi;
use App\Services\Zekat\ZekatVarsayilanlari;
use App\Services\Zekat\ZekatYilKaydi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Zekât hesabı (önizleme) ve zekât yılı kayıtları: anlık görüntü (taslak) → kesinleştirme → ödemeler.
 */
class ZekatController extends Controller
{
    public function __construct(private ZekatHesabi $hesap, private ZekatYilKaydi $kayit) {}

    /** Portföydeki güncel değerlerle zekât önizlemesi; hiçbir şey kaydetmez */
    public function hesapla(Request $request): JsonResponse
    {
        $user = $request->user();
        $sonuc = $this->hesap->hesapla($user);
        $bilgi = $this->kayit->gunBilgisi(ZekatAyari::haneIcin((int) $user->hane_id));

        return response()->json([
            ...$sonuc,
            'zekat_gunu' => $bilgi,
            // Hawl basitleştirilmiş: kesin sonuç yalnızca zekât gününde; başka gün tahmindir
            'tahmini' => $bilgi['tarih'] !== $sonuc['tarih'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'yillar' => ZekatYili::hanenin($request->user())->with('odemeler')->orderByDesc('zekat_gunu')->get()
                ->map(fn (ZekatYili $y): array => $y->ozet())->values(),
            'uyari' => ZekatVarsayilanlari::UYARI,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'zekat_gunu' => ['nullable', 'date'],
            'not' => ['nullable', 'string', 'max:500'],
        ]);
        $yil = $this->kayit->taslakOlustur($request->user(), isset($veri['zekat_gunu']) ? Carbon::parse($veri['zekat_gunu']) : null, $veri['not'] ?? null);

        return response()->json(['yil' => $yil->ozet(true), 'message' => 'Zekât yılı taslak olarak kaydedildi.'], 201);
    }

    public function show(ZekatYili $yil): JsonResponse
    {
        Gate::authorize('hane-kaydi', $yil);

        return response()->json(['yil' => $yil->load(['kalemler', 'borclar'])->ozet(true), 'uyari' => ZekatVarsayilanlari::UYARI]);
    }

    /** Yalnızca taslağın notu değişir; kesinleşmiş kayıt salt okunurdur */
    public function update(Request $request, ZekatYili $yil): JsonResponse
    {
        Gate::authorize('hane-kaydi', $yil);
        if ($yil->kesinlesmisMi()) {
            return response()->json(['message' => 'Kesinleşmiş zekât yılı düzenlenemez.', 'errors' => ['yil' => ['Kesinleşmiş zekât yılı düzenlenemez.']]], 422);
        }
        $veri = $request->validate(['not' => ['nullable', 'string', 'max:500']]);
        $yil->update(['not' => $veri['not'] ?? null]);

        return response()->json(['yil' => $yil->load(['kalemler', 'borclar'])->ozet(true), 'message' => 'Not kaydedildi.']);
    }

    public function kesinlestir(ZekatYili $yil): JsonResponse
    {
        Gate::authorize('hane-kaydi', $yil);
        $this->kayit->kesinlestir($yil);

        return response()->json(['yil' => $yil->load(['kalemler', 'borclar'])->ozet(true), 'message' => 'Zekât yılı kesinleştirildi.']);
    }

    public function destroy(ZekatYili $yil): JsonResponse
    {
        Gate::authorize('hane-kaydi', $yil);
        $this->kayit->sil($yil);

        return response()->json(['message' => 'Taslak silindi.']);
    }
}
