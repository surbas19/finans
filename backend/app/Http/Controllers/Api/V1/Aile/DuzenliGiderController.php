<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\DuzenliGider;
use App\Models\GiderKaynagi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Services\Aile\ButceRaporu;
use App\Services\Aile\NakitKaynaklari;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gerçekleşen kartsız giderler (düzenli ödeme ya da tek seferlik). Kayıt seçilen döneme (ait olduğu ay) yazılır.
 * Ödeme yeri Ev ise ev nakdi kasasından, Banka ise seçilen portföy hesabının Nakit (TL) bakiyesinden düşer
 * (`NakitKaynaklari`); yetmeyen bakiyede kayıt reddedilir. Ödeme yeri boş (eski kayıt) hiçbir bakiyeden düşmez.
 */
class DuzenliGiderController extends Controller
{
    public function __construct(private NakitKaynaklari $kaynaklar) {}

    public function index(Request $request, ButceRaporu $rapor): JsonResponse
    {
        $veri = $request->validate(['donem' => ['nullable', 'regex:'.Donem::DESEN]]);
        $donem = $veri['donem'] ?? Donem::buAy();
        $giderler = DuzenliGider::hanenin($request->user())->where('donem', $donem)
            ->orderByDesc('tarih')->orderByDesc('id')->get();

        return response()->json([
            'giderler' => $giderler->map(fn (DuzenliGider $g): array => $g->ozet()),
            'beklenenler' => $rapor->giderOzeti((int) $request->user()->hane_id, $donem)['beklenenler'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request, null);
        $gider = DB::transaction(function () use ($request, $veri): DuzenliGider {
            $gider = new DuzenliGider([...$veri, 'hane_id' => $request->user()->hane_id, 'olusturan_id' => $request->user()->id]);
            $this->kaynaktanDus($request, $gider, null);
            $gider->save();

            return $gider;
        });

        return response()->json(['gider' => $gider->ozet(), 'message' => Donem::etiket($gider->donem).' gideri kaydedildi.'], 201);
    }

    public function update(Request $request, DuzenliGider $gider): JsonResponse
    {
        Gate::authorize('hane-kaydi', $gider);
        $veri = $this->dogrula($request, $gider);
        DB::transaction(function () use ($request, $gider, $veri): void {
            $eskiIslem = $gider->islem_id;
            $gider->fill($veri);
            $this->kaynaktanDus($request, $gider, $gider->id, $eskiIslem);
            $gider->save();
        });

        return response()->json(['gider' => $gider->ozet(), 'message' => 'Gider kaydedildi.']);
    }

    public function destroy(DuzenliGider $gider): JsonResponse
    {
        Gate::authorize('hane-kaydi', $gider);
        DB::transaction(function () use ($gider): void {
            // Banka harcamasıysa hesaba yazılan Nakit satışı da silinir (para hesaba geri döner)
            if ($gider->islem_id !== null) {
                Islem::whereKey($gider->islem_id)->delete();
            }
            $gider->delete();
        });

        return response()->json(['message' => 'Gider silindi.']);
    }

    /**
     * Ödeme yerine göre kaynaktan düşer: Ev → kasa bakiyesi denetlenir; Banka → hesaba Nakit satışı yazılır (eski satış
     * silinip yeniden yazılır, böylece tutar / tarih / hesap değişimi tek yerde çözülür).
     */
    private function kaynaktanDus(Request $request, DuzenliGider $gider, ?int $haricGiderId, ?int $eskiIslemId = null): void
    {
        if ($eskiIslemId !== null) {
            Islem::whereKey($eskiIslemId)->delete();
            $gider->islem_id = null;
        }
        $kurus = Kurus::kurus($gider->tutar);
        if ($gider->odeme_yeri === 'ev') {
            $this->kaynaklar->evYeterliMi((int) $request->user()->hane_id, $kurus, $haricGiderId);
            $gider->hesap_id = null;
        } elseif ($gider->odeme_yeri === 'banka') {
            $hesap = Hesap::findOrFail((int) $gider->hesap_id);
            $islem = $this->kaynaklar->bankaSatisiYaz($request->user(), $hesap, $kurus, $gider->tarih->toDateString(), $gider->aciklama);
            $gider->islem_id = $islem->id;
        } else {
            $gider->hesap_id = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?DuzenliGider $gider): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'gider_kaynagi_id' => ['nullable', 'integer', Rule::exists('gider_kaynaklari', 'id')->where('hane_id', $haneId)],
            'kategori_id' => ['nullable', 'integer', Rule::exists('harcama_kategorileri', 'id')->where('hane_id', $haneId)],
            'aciklama' => ['required', 'string', 'max:120'],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            // Paranın çıktığı yer: Ev ev nakdi kasasından, Banka seçilen hesabın nakdinden düşer
            'odeme_yeri' => ['nullable', 'in:ev,banka'],
            'hesap_id' => [Rule::requiredIf($request->input('odeme_yeri') === 'banka'), 'nullable', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
            // Ait olduğu ay: geçmiş aylar serbest; en çok gelecek ay
            'donem' => ['required', 'regex:'.Donem::DESEN, function (string $alan, mixed $deger, \Closure $hata): void {
                if (is_string($deger) && Donem::gecerli($deger) && $deger > Donem::ekle(Donem::buAy(), 1)) {
                    $hata('Dönem en çok gelecek ay olabilir.');
                }
            }],
            'not' => ['nullable', 'string', 'max:500'],
        ], [
            'tarih.before_or_equal' => 'Gelecek tarihli gider girilmez; beklenen giderler düzenli ödemeden gelir.',
            'donem.regex' => 'Dönem YYYY-AA biçiminde olmalı.',
            'hesap_id.required' => 'Banka ödemesinde düşülecek hesabı seçin.',
        ], ['gider_kaynagi_id' => 'ödeme', 'kategori_id' => 'kategori', 'aciklama' => 'açıklama', 'tutar' => 'tutar', 'tarih' => 'tarih', 'donem' => 'dönem', 'hesap_id' => 'hesap']);

        if (! empty($veri['gider_kaynagi_id'])) {
            $kaynak = GiderKaynagi::findOrFail($veri['gider_kaynagi_id']);
            $ayni = DuzenliGider::where('gider_kaynagi_id', $kaynak->id)->where('donem', $veri['donem'])
                ->when($gider, fn ($q) => $q->whereKeyNot($gider->id))->exists();
            if ($ayni) {
                throw ValidationException::withMessages(['donem' => "\"{$kaynak->ad}\" ödemesinin ".Donem::etiket($veri['donem']).' gideri zaten girilmiş.']);
            }
            $veri['kategori_id'] ??= $kaynak->kategori_id;
        }
        $veri['tutar'] = Kurus::decimal(Kurus::kurus($veri['tutar']));
        $veri['aciklama'] = trim($veri['aciklama']);

        return $veri;
    }
}
