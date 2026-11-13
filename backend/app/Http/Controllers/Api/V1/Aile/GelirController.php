<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\GelirDuzeni;
use App\Enums\GelirTuru;
use App\Http\Controllers\Controller;
use App\Models\Gelir;
use App\Models\GelirKaynagi;
use App\Services\Aile\ButceRaporu;
use App\Services\Aile\DovizKuru;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gerçekleşen gelirler. Kayıt seçilen döneme (ait olduğu ay) yazılır; geçmiş ay girilebilir. Döviz gelirinin TL
 * karşılığı o günün kuruyla hesaplanır, kur yoksa kullanıcı girer.
 */
class GelirController extends Controller
{
    public function index(Request $request, ButceRaporu $rapor): JsonResponse
    {
        $veri = $request->validate([
            'donem' => ['nullable', 'regex:'.Donem::DESEN],
            'yil' => ['nullable', 'integer', 'between:2000,2100'],
            'uye_id' => ['nullable', 'integer'],
            'tur' => ['nullable', Rule::enum(GelirTuru::class)],
            'duzen' => ['nullable', Rule::enum(GelirDuzeni::class)],
        ]);
        $donem = $veri['donem'] ?? (isset($veri['yil']) ? null : Donem::buAy());
        $gelirler = Gelir::hanenin($request->user())
            ->when($donem, fn ($q, $d) => $q->where('donem', $d))
            ->when($veri['yil'] ?? null, fn ($q, $y) => $q->where('donem', 'like', $y.'-%'))
            ->when($veri['uye_id'] ?? null, fn ($q, $id) => $q->where('uye_id', $id))
            ->when($veri['tur'] ?? null, fn ($q, $t) => $q->where('tur', $t))
            ->when($veri['duzen'] ?? null, fn ($q, $d) => $q->where('duzen', $d))
            ->orderByDesc('donem')->orderByDesc('tarih')->orderByDesc('id')->limit(1000)->get();

        return response()->json([
            'gelirler' => $gelirler->map(fn (Gelir $g): array => $g->ozet()),
            'ozet' => $donem ? $rapor->gelirOzeti((int) $request->user()->hane_id, $donem) : null,
        ]);
    }

    public function store(Request $request, DovizKuru $kur): JsonResponse
    {
        $veri = $this->dogrula($request, null, $kur);
        $gelir = Gelir::create([...$veri, 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);

        return response()->json(['gelir' => $gelir->ozet(), 'message' => Donem::etiket($gelir->donem).' geliri kaydedildi.'], 201);
    }

    public function update(Request $request, Gelir $gelir, DovizKuru $kur): JsonResponse
    {
        Gate::authorize('hane-kaydi', $gelir);
        $gelir->update($this->dogrula($request, $gelir, $kur));

        return response()->json(['gelir' => $gelir->ozet(), 'message' => 'Gelir kaydedildi.']);
    }

    public function destroy(Gelir $gelir): JsonResponse
    {
        Gate::authorize('hane-kaydi', $gelir);
        $gelir->delete();

        return response()->json(['message' => 'Gelir silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?Gelir $gelir, DovizKuru $kur): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'uye_id' => ['required', 'integer', Rule::exists('hane_uyeleri', 'id')->where('hane_id', $haneId)],
            'gelir_kaynagi_id' => ['nullable', 'integer', Rule::exists('gelir_kaynaklari', 'id')->where('hane_id', $haneId)],
            'tur' => ['required', Rule::enum(GelirTuru::class)],
            'duzen' => ['required', Rule::enum(GelirDuzeni::class)],
            'aciklama' => ['nullable', 'string', 'max:120'],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'para_birimi' => ['required', 'string', Rule::in(['TRY', ...$kur->kodlar()])],
            'tl_tutar' => ['nullable', 'numeric', 'gt:0', 'max:100000000000', 'decimal:0,2'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            // Ait olduğu ay: geçmiş aylar serbest; en çok gelecek ay (ör. ayın son günü yatan sonraki ayın maaşı)
            'donem' => ['required', 'regex:'.Donem::DESEN, function (string $alan, mixed $deger, \Closure $hata): void {
                if (is_string($deger) && Donem::gecerli($deger) && $deger > Donem::ekle(Donem::buAy(), 1)) {
                    $hata('Dönem en çok gelecek ay olabilir.');
                }
            }],
            'not' => ['nullable', 'string', 'max:500'],
        ], [
            'tarih.before_or_equal' => 'Gelecek tarihli gelir girilmez; beklenen gelirler düzenli kaynaktan gelir.',
            'donem.regex' => 'Dönem YYYY-AA biçiminde olmalı.',
        ], [
            'uye_id' => 'kişi', 'gelir_kaynagi_id' => 'kaynak', 'tur' => 'gelir türü', 'duzen' => 'düzen',
            'tutar' => 'tutar', 'para_birimi' => 'para birimi', 'tl_tutar' => 'TL karşılığı', 'tarih' => 'tarih', 'donem' => 'dönem',
        ]);

        if (! empty($veri['gelir_kaynagi_id'])) {
            $kaynak = GelirKaynagi::findOrFail($veri['gelir_kaynagi_id']);
            if ($kaynak->uye_id !== (int) $veri['uye_id']) {
                throw ValidationException::withMessages(['gelir_kaynagi_id' => 'Bu kaynak seçilen kişinin değil.']);
            }
            $ayni = Gelir::where('gelir_kaynagi_id', $kaynak->id)->where('donem', $veri['donem'])
                ->when($gelir, fn ($q) => $q->whereKeyNot($gelir->id))->exists();
            if ($ayni) {
                throw ValidationException::withMessages(['donem' => "\"{$kaynak->ad}\" kaynağının ".Donem::etiket($veri['donem']).' geliri zaten girilmiş.']);
            }
        }

        $tutar = Kurus::decimal(Kurus::kurus($veri['tutar']));
        if ($veri['para_birimi'] === 'TRY') {
            $veri['tl_tutar'] = $tutar;
            $veri['kur'] = null;
        } elseif (! empty($veri['tl_tutar'])) {
            // Kullanıcı TL karşılığını kendisi girdi (ör. bankanın uyguladığı kur)
            $veri['tl_tutar'] = Kurus::decimal(Kurus::kurus($veri['tl_tutar']));
            $veri['kur'] = null;
        } else {
            $oran = $kur->kur($veri['para_birimi'], $veri['tarih']);
            if ($oran === null) {
                throw ValidationException::withMessages(['tl_tutar' => 'Bu tarih için '.$veri['para_birimi'].' kuru bulunamadı; TL karşılığını girin.']);
            }
            $veri['tl_tutar'] = DovizKuru::tlDecimal($tutar, $oran);
            $veri['kur'] = $oran;
        }
        $veri['tutar'] = $tutar;
        $veri['aciklama'] = isset($veri['aciklama']) ? trim($veri['aciklama']) : null;

        return $veri;
    }
}
