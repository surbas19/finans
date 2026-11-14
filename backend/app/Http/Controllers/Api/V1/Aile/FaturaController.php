<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\FaturaDurumu;
use App\Enums\KartIslemDurumu;
use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\FaturaSaglayicisi;
use App\Models\KartIslemi;
use App\Services\Aile\FaturaEslestirici;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Faturalar: hizmet ayına göre liste, elle fatura (geçmiş dönem dahil), kart satırına bağlama / bağı çözme.
 */
class FaturaController extends Controller
{
    public function __construct(private FaturaEslestirici $eslestirici) {}

    public function index(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'donem' => ['nullable', 'regex:'.Donem::DESEN],
            'yil' => ['nullable', 'integer', 'between:2000,2100'],
            'bilinmeyen' => ['nullable', 'boolean'],
            'saglayici_id' => ['nullable', 'integer'],
            'fatura_turu_id' => ['nullable', 'integer'],
            'durum' => ['nullable', Rule::enum(FaturaDurumu::class)],
            'kart_id' => ['nullable', 'integer'],
            'banka_id' => ['nullable', 'integer'],
        ]);
        $faturalar = Fatura::hanenin($request->user())
            ->when($veri['donem'] ?? null, fn ($q, $d) => $q->where('hizmet_donemi', $d))
            ->when($veri['yil'] ?? null, fn ($q, $y) => $q->where('hizmet_donemi', 'like', $y.'-%'))
            ->when($request->boolean('bilinmeyen'), fn ($q) => $q->whereNull('hizmet_donemi'))
            ->when($veri['saglayici_id'] ?? null, fn ($q, $id) => $q->where('saglayici_id', $id))
            ->when($veri['fatura_turu_id'] ?? null, fn ($q, $id) => $q->where('fatura_turu_id', $id))
            ->when($veri['durum'] ?? null, fn ($q, $d) => $q->where('durum', $d))
            ->when($veri['kart_id'] ?? null, fn ($q, $id) => $q->whereHas('kartIslemi', fn ($k) => $k->where('kart_id', $id)))
            ->when($veri['banka_id'] ?? null, fn ($q, $id) => $q->whereHas('kartIslemi.kart', fn ($k) => $k->where('banka_id', $id)))
            ->with(['saglayici:id,ad', 'kartIslemi:id,kart_id,islem_tarihi,aciklama,tutar'])
            ->orderByRaw('hizmet_donemi is null desc')->orderByDesc('hizmet_donemi')->orderByDesc('id')->limit(1000)->get();

        return response()->json([
            'faturalar' => $faturalar->map(fn (Fatura $f): array => [
                ...$f->ozet(),
                'saglayici_ad' => $f->saglayici->ad,
                'kart_islemi' => $f->kartIslemi ? [
                    'id' => $f->kartIslemi->id,
                    'kart_id' => $f->kartIslemi->kart_id,
                    'islem_tarihi' => $f->kartIslemi->islem_tarihi?->toDateString(),
                    'aciklama' => $f->kartIslemi->aciklama,
                    'tutar' => Kurus::tl(Kurus::kurus($f->kartIslemi->tutar)),
                ] : null,
            ]),
            'toplam' => Kurus::tl($faturalar->reduce(fn (int $t, Fatura $f): int => $t + Kurus::kurus($f->tutar), 0)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request, null);
        $fatura = Fatura::create([...$veri, 'hane_id' => $request->user()->hane_id, 'kaynak' => 'elle', 'olusturan_id' => $request->user()->id]);

        return response()->json([
            'fatura' => $fatura->ozet(),
            'message' => ($fatura->hizmet_donemi ? Donem::etiket($fatura->hizmet_donemi).' faturası' : 'Fatura').' kaydedildi.',
        ], 201);
    }

    public function update(Request $request, Fatura $fatura): JsonResponse
    {
        Gate::authorize('hane-kaydi', $fatura);
        $fatura->update($this->dogrula($request, $fatura));

        return response()->json(['fatura' => $fatura->ozet(), 'message' => 'Fatura kaydedildi.']);
    }

    public function destroy(Fatura $fatura): JsonResponse
    {
        Gate::authorize('hane-kaydi', $fatura);
        $fatura->delete();

        return response()->json(['message' => 'Fatura silindi; bağlı kart satırı giderde kalır.']);
    }

    /**
     * Faturayı (bağsız) onaylı bir kart satırına bağlar: ödendi olur, ödeme tarihi kart satırının tarihi; dönemi
     * bilinmiyorsa satırın ekstresinin kesim ayı
     */
    public function bagla(Request $request, Fatura $fatura): JsonResponse
    {
        Gate::authorize('hane-kaydi', $fatura);
        $veri = $request->validate([
            'kart_islemi_id' => ['required', 'integer', Rule::exists('kart_islemleri', 'id')->where('hane_id', $fatura->hane_id)->where('durum', KartIslemDurumu::Onayli->value)],
        ], [], ['kart_islemi_id' => 'kart satırı']);
        if ($fatura->kart_islemi_id !== null) {
            throw ValidationException::withMessages(['kart_islemi_id' => 'Fatura zaten bir kart satırına bağlı; önce bağı çözün.']);
        }
        $islem = KartIslemi::findOrFail($veri['kart_islemi_id']);
        DB::transaction(function () use ($fatura, $islem): void {
            if (Fatura::where('kart_islemi_id', $islem->id)->exists()) {
                throw ValidationException::withMessages(['kart_islemi_id' => "\"{$islem->aciklama}\" başka bir faturaya bağlı."]);
            }
            $fatura->forceFill([
                'kart_islemi_id' => $islem->id,
                'durum' => FaturaDurumu::Odendi,
                'odeme_tarihi' => $islem->islem_tarihi,
                'eslesme' => ['guven' => 'kullanici', 'gerekce' => ['Kart satırını siz bağladınız.'], 'otomatik' => false],
                ...$this->eslestirici->bosDonemiTamamla($fatura, $islem),
            ])->save();
        });

        return response()->json(['fatura' => $fatura->ozet(), 'message' => 'Fatura kart satırına bağlandı.']);
    }

    /** Yanlış eşleşmeyi düzeltme: bağ çözülür, fatura bekliyor'a döner; kart harcaması giderde kalır */
    public function coz(Fatura $fatura): JsonResponse
    {
        Gate::authorize('hane-kaydi', $fatura);
        $this->eslestirici->coz($fatura);

        return response()->json(['fatura' => $fatura->ozet(), 'message' => 'Bağ çözüldü; fatura ödenmemiş görünüyor.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?Fatura $fatura): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'saglayici_id' => ['required', 'integer', Rule::exists('fatura_saglayicilari', 'id')->where('hane_id', $haneId)],
            // Hizmet ayı: geçmiş aylar serbest; boş = bilinmiyor
            'hizmet_donemi' => ['nullable', 'regex:'.Donem::DESEN],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:100000000', 'decimal:0,2'],
            'son_odeme_tarihi' => ['nullable', 'date'],
            'durum' => ['required', Rule::enum(FaturaDurumu::class)],
            'odeme_tarihi' => ['nullable', 'required_if:durum,odendi', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:500'],
        ], [
            'odeme_tarihi.required_if' => 'Ödenmiş faturada ödeme tarihi gerekir.',
            'hizmet_donemi.regex' => 'Hizmet dönemi YYYY-AA olmalı.',
        ], ['saglayici_id' => 'sağlayıcı', 'hizmet_donemi' => 'hizmet dönemi', 'tutar' => 'tutar', 'son_odeme_tarihi' => 'son ödeme tarihi', 'odeme_tarihi' => 'ödeme tarihi']);

        $saglayici = FaturaSaglayicisi::findOrFail($veri['saglayici_id']);
        if (! empty($veri['hizmet_donemi'])) {
            $ayni = Fatura::where('saglayici_id', $saglayici->id)->where('hizmet_donemi', $veri['hizmet_donemi'])
                ->when($fatura, fn ($q) => $q->whereKeyNot($fatura->id))->exists();
            if ($ayni) {
                throw ValidationException::withMessages(['hizmet_donemi' => "{$saglayici->ad} sağlayıcısının ".Donem::etiket($veri['hizmet_donemi']).' faturası zaten var.']);
            }
        }
        // Kart satırına bağlı faturanın ödemesi kart satırından gelir
        if ($fatura?->kart_islemi_id !== null && $veri['durum'] === FaturaDurumu::Bekliyor->value) {
            throw ValidationException::withMessages(['durum' => 'Kart satırına bağlı fatura ödenmiş sayılır; önce bağı çözün.']);
        }
        $veri['fatura_turu_id'] = $saglayici->fatura_turu_id;
        $veri['tutar'] = Kurus::decimal(Kurus::kurus($veri['tutar']));
        $veri['hizmet_donemi'] = $veri['hizmet_donemi'] ?? null;
        if ($fatura === null || $fatura->hizmet_donemi !== $veri['hizmet_donemi']) {
            // Dönemi kullanıcı yazdı / değiştirdi; değişmediyse kaynağı (ör. ekstre açıklaması) korunur
            $veri['donem_kaynagi'] = $veri['hizmet_donemi'] !== null ? 'kullanici' : null;
        }
        if ($fatura?->kart_islemi_id === null && $veri['durum'] === FaturaDurumu::Bekliyor->value) {
            $veri['odeme_tarihi'] = null;
        }
        if ($fatura?->kart_islemi_id !== null) {
            unset($veri['odeme_tarihi']); // kart satırının tarihi
        }

        return $veri;
    }
}
