<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Http\Controllers\Controller;
use App\Models\KartIslemi;
use App\Services\Aile\ButceRaporu;
use App\Services\Aile\FaturaEslestirici;
use App\Services\Aile\KartIslemKurallari;
use App\Services\Aile\KategoriBulucu;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kart işlemleri: süzgeçli liste (ay: rapor / işlem / ekstre ayına göre; kişi, banka, kart, kategori, tür), elle
 * ekleme ve düzeltme. Toplamlar ButceRaporu'nun kuralıyla (ödeme / transfer / nakit avans gider değildir).
 */
class KartIslemiController extends Controller
{
    public function __construct(private KartIslemKurallari $kurallar, private ButceRaporu $rapor) {}

    public function index(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'ay' => ['nullable', 'regex:'.Donem::DESEN],
            'donem_turu' => ['nullable', Rule::in(['rapor', 'islem', 'ekstre'])],
            'uye_id' => ['nullable', 'integer'],
            'banka_id' => ['nullable', 'integer'],
            'kart_id' => ['nullable', 'integer'],
            'kategori_id' => ['nullable', 'regex:/^(\d+|yok)$/'],
            'tur' => ['nullable', Rule::enum(KartIslemTuru::class)],
            'ekstre_id' => ['nullable', 'integer'],
            'durum' => ['nullable', Rule::enum(KartIslemDurumu::class)],
        ]);
        $ay = $veri['ay'] ?? null;
        $sorgu = KartIslemi::hanenin($request->user())
            ->where('kart_islemleri.durum', $veri['durum'] ?? KartIslemDurumu::Onayli->value)
            ->when($ay, function ($q) use ($ay, $veri): void {
                match ($veri['donem_turu'] ?? 'rapor') {
                    'islem' => $q->whereBetween('islem_tarihi', [Donem::ilkGun($ay)->toDateString(), Donem::sonGun($ay)->toDateString()]),
                    'ekstre' => $q->whereHas('ekstre', fn ($e) => $e->whereBetween('kesim_tarihi', [Donem::ilkGun($ay)->toDateString(), Donem::sonGun($ay)->toDateString()])),
                    default => $q->where('rapor_donemi', $ay),
                };
            })
            ->when($veri['uye_id'] ?? null, fn ($q, $id) => $q->whereHas('kart', fn ($k) => $k->where('sahip_uye_id', $id)))
            ->when($veri['banka_id'] ?? null, fn ($q, $id) => $q->whereHas('kart', fn ($k) => $k->where('banka_id', $id)))
            ->when($veri['kart_id'] ?? null, fn ($q, $id) => $q->where('kart_id', $id))
            ->when(($veri['kategori_id'] ?? null) === 'yok', fn ($q) => $q->whereNull('kategori_id'))
            ->when(is_numeric($veri['kategori_id'] ?? null), fn ($q) => $q->where('kategori_id', (int) $veri['kategori_id']))
            ->when($veri['tur'] ?? null, fn ($q, $t) => $q->where('tur', $t))
            ->when($veri['ekstre_id'] ?? null, fn ($q, $id) => $q->where('ekstre_id', $id));
        $islemler = $sorgu->with('ekstre:id,kesim_tarihi')->orderByDesc('islem_tarihi')->orderByDesc('id')->limit(1000)->get();

        $haric = $islemler->filter(fn (KartIslemi $i): bool => $i->tur->giderYonu() === 0);

        return response()->json([
            'islemler' => $islemler->map(fn (KartIslemi $i): array => [...$i->ozet(), 'ekstre_donemi' => $i->ekstre?->donemEtiketi()]),
            'toplam' => [
                'gider' => Kurus::tl($islemler->reduce(fn (int $t, KartIslemi $i): int => $t + $this->rapor->giderEtkisi($i), 0)),
                'gidere_girmeyen' => Kurus::tl($haric->reduce(fn (int $t, KartIslemi $i): int => $t + Kurus::kurus($i->tutar), 0)),
                'adet' => $islemler->count(),
            ],
        ]);
    }

    public function store(Request $request, KategoriBulucu $bulucu): JsonResponse
    {
        $veri = $this->dogrula($request, null);
        $islem = new KartIslemi([
            ...$veri,
            'hane_id' => $request->user()->hane_id,
            'durum' => KartIslemDurumu::Onayli,
            'olusturan_id' => $request->user()->id,
        ]);
        $islem->kategori_id ??= $bulucu->bul((int) $islem->hane_id, $islem->aciklama);
        DB::transaction(fn () => $this->kurallar->onayla($islem, null, null));

        return response()->json(['islem' => $islem->ozet(), 'message' => 'İşlem eklendi ('.Donem::etiket((string) $islem->rapor_donemi).').'], 201);
    }

    public function update(Request $request, KartIslemi $islem): JsonResponse
    {
        Gate::authorize('hane-kaydi', $islem);
        $islem->fill($this->dogrula($request, $islem));
        DB::transaction(function () use ($islem): void {
            if ($islem->durum === KartIslemDurumu::Onayli) {
                $this->kurallar->onayla($islem, $islem->ekstre, null);
            } else {
                $islem->save();
            }
        });

        return response()->json(['islem' => $islem->ozet(), 'message' => 'İşlem kaydedildi.']);
    }

    /**
     * Elle girilen satır silinir; ekstreden gelen satır dışarıda bırakılır (ekstre tekrar yüklenince yine gelmesin diye
     * ekstreyle birlikte durur, raporlara girmez).
     */
    public function destroy(KartIslemi $islem, FaturaEslestirici $faturalar): JsonResponse
    {
        Gate::authorize('hane-kaydi', $islem);
        // Bağlı fatura ödenmemiş sayılır (yanlış satırsa başka satıra bağlanır)
        $faturalar->islemCikti($islem);
        if ($islem->ekstre_id !== null) {
            $islem->update(['durum' => KartIslemDurumu::Atlandi, 'parmak_izi' => null, 'rapor_donemi' => null]);

            return response()->json(['islem' => $islem->ozet(), 'message' => 'Satır dışarıda bırakıldı; raporlara girmez.']);
        }
        $islem->delete();

        return response()->json(['message' => 'İşlem silindi.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dogrula(Request $request, ?KartIslemi $islem): array
    {
        $haneId = $request->user()->hane_id;
        $veri = $request->validate([
            'kart_id' => ['required', 'integer', Rule::exists('kartlar', 'id')->where('hane_id', $haneId)],
            'islem_tarihi' => ['required', 'date', 'before_or_equal:today'],
            'aciklama' => ['required', 'string', 'max:255'],
            'tutar' => ['required', 'numeric', 'gt:0', 'max:10000000000', 'decimal:0,2'],
            'tur' => ['required', Rule::enum(KartIslemTuru::class)],
            'taksit_no' => ['nullable', 'integer', 'between:1,99', 'required_with:taksit_sayisi', 'lte:taksit_sayisi'],
            'taksit_sayisi' => ['nullable', 'integer', 'between:2,99', 'required_with:taksit_no'],
            'taksit_toplam' => ['nullable', 'numeric', 'gt:0', 'max:10000000000', 'decimal:0,2'],
            'kategori_id' => ['nullable', 'integer', Rule::exists('harcama_kategorileri', 'id')->where('hane_id', $haneId)],
            'iade_islem_id' => ['nullable', 'integer', Rule::exists('kart_islemleri', 'id')->where('hane_id', $haneId)],
        ], ['islem_tarihi.before_or_equal' => 'İleri tarihli işlem girilmez.'], [
            'kart_id' => 'kart', 'islem_tarihi' => 'işlem tarihi', 'aciklama' => 'açıklama', 'tutar' => 'tutar', 'tur' => 'tür',
            'taksit_no' => 'taksit', 'taksit_sayisi' => 'taksit sayısı', 'kategori_id' => 'kategori', 'iade_islem_id' => 'iade edilen işlem',
        ]);

        if (! empty($veri['iade_islem_id'])) {
            $asil = KartIslemi::find($veri['iade_islem_id']);
            if ($veri['tur'] !== KartIslemTuru::Iade->value || $asil->tur !== KartIslemTuru::Harcama
                || $asil->kart_id !== (int) $veri['kart_id'] || $asil->id === $islem?->id) {
                throw ValidationException::withMessages(['iade_islem_id' => 'İade, aynı karttaki bir harcamaya bağlanabilir.']);
            }
        } elseif ($veri['tur'] !== KartIslemTuru::Iade->value) {
            $veri['iade_islem_id'] = null;
        }
        $veri['tutar'] = Kurus::decimal(Kurus::kurus($veri['tutar']));
        $veri['taksit_toplam'] = isset($veri['taksit_toplam']) ? Kurus::decimal(Kurus::kurus($veri['taksit_toplam'])) : null;
        $veri['aciklama'] = trim($veri['aciklama']);

        return $veri;
    }
}
