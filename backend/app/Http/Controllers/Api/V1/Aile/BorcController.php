<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Borc;
use App\Models\BorcOdemesi;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Borç / alacak defteri ve kısmi ödemeleri. Bütçe toplamlarına, ev nakdine ve zekâta karışmaz.
 */
class BorcController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $borclar = Borc::hanenin($request->user())->with('odemeler')->orderByDesc('tarih')->orderByDesc('id')->get();
        // Açık kayıtlar önce (vadesi yakın olan üstte), kapalılar sonda
        $sirali = $borclar->sortBy([
            fn (Borc $a, Borc $b): int => ($a->kalanKurus() === 0) <=> ($b->kalanKurus() === 0),
            fn (Borc $a, Borc $b): int => ($a->vade?->toDateString() ?? '9999') <=> ($b->vade?->toDateString() ?? '9999'),
        ])->values();
        $kalan = fn (string $yon): int => $borclar->where('yon', $yon)->reduce(fn (int $t, Borc $b): int => $t + $b->kalanKurus(), 0);

        return response()->json([
            'borclar' => $sirali->map(fn (Borc $b): array => $b->ozet()),
            'toplam_borc' => Kurus::tl($kalan(Borc::BORC)),
            'toplam_alacak' => Kurus::tl($kalan(Borc::ALACAK)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $borc = Borc::create([...$this->dogrula($request), 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json(['borc' => $borc->load('odemeler')->ozet(), 'message' => 'Kaydedildi.'], 201);
    }

    public function update(Request $request, Borc $borc): JsonResponse
    {
        Gate::authorize('hane-kaydi', $borc);
        $veri = $this->dogrula($request);
        // Tutar ödenenin altına indirilemez
        if (Kurus::kurus($veri['tutar']) < $borc->odenenKurus()) {
            throw ValidationException::withMessages(['tutar' => 'Tutar şimdiye kadar ödenenden az olamaz.']);
        }
        $borc->update($veri);

        return response()->json(['borc' => $borc->load('odemeler')->ozet(), 'message' => 'Kaydedildi.']);
    }

    public function destroy(Borc $borc): JsonResponse
    {
        Gate::authorize('hane-kaydi', $borc);
        $borc->delete();

        return response()->json(['message' => 'Silindi.']);
    }

    public function odemeEkle(Request $request, Borc $borc): JsonResponse
    {
        Gate::authorize('hane-kaydi', $borc);
        $veri = $request->validate([
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:500'],
        ], [], ['tutar' => 'tutar', 'tarih' => 'tarih', 'not' => 'not']);
        $kurus = Kurus::kurus($veri['tutar']);
        $borc->load('odemeler');
        if ($kurus > $borc->kalanKurus()) {
            throw ValidationException::withMessages(['tutar' => 'Tutar kalan borçtan ('.number_format($borc->kalanKurus() / 100, 2, ',', '.').' ₺) fazla olamaz.']);
        }
        $borc->odemeler()->create([...$veri, 'tutar' => Kurus::decimal($kurus), 'hane_id' => $borc->hane_id]);

        return response()->json(['borc' => $borc->refresh()->load('odemeler')->ozet(), 'message' => 'Ödeme kaydedildi.'], 201);
    }

    public function odemeSil(Borc $borc, BorcOdemesi $odeme): JsonResponse
    {
        Gate::authorize('hane-kaydi', $borc);
        abort_unless($odeme->borc_id === $borc->id, 404);
        $odeme->delete();

        return response()->json(['borc' => $borc->refresh()->load('odemeler')->ozet(), 'message' => 'Ödeme silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request): array
    {
        $veri = $request->validate([
            'yon' => ['required', 'in:'.Borc::BORC.','.Borc::ALACAK],
            'kisi' => ['required', 'string', 'max:80'],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'vade' => ['nullable', 'date', 'after_or_equal:tarih'],
            'not' => ['nullable', 'string', 'max:500'],
        ], [], ['yon' => 'yön', 'kisi' => 'kişi', 'tutar' => 'tutar', 'tarih' => 'tarih', 'vade' => 'vade', 'not' => 'not']);
        $veri['kisi'] = trim($veri['kisi']);
        $veri['tutar'] = Kurus::decimal(Kurus::kurus($veri['tutar']));

        return $veri;
    }
}
