<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Http\Controllers\Controller;
use App\Models\Ekstre;
use App\Models\HarcamaKategorisi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Services\Aile\ButceRaporu;
use App\Services\Aile\FaturaEslestirici;
use App\Services\Aile\KartIslemKurallari;
use App\Services\Aile\KategoriAtayici;
use App\Services\Aile\KategoriBulucu;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Builder;
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
            'tarih_bas' => ['nullable', 'date_format:Y-m-d'],
            'tarih_bit' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tarih_bas'],
            'ara' => ['nullable', 'string', 'max:100'],
            'guncel' => ['nullable', 'boolean'],
        ]);
        $ay = $veri['ay'] ?? null;
        // Süzgeçsiz varsayılan görünüm: her kartın güncel ekstre dönemi (arama, tarih, ay, ekstre verilmişse tüm dönemler)
        $guncel = (bool) ($veri['guncel'] ?? false) && ! $ay && empty($veri['tarih_bas']) && empty($veri['tarih_bit'])
            && trim((string) ($veri['ara'] ?? '')) === '' && empty($veri['ekstre_id']);
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
            ->when($veri['ekstre_id'] ?? null, fn ($q, $id) => $q->where('ekstre_id', $id))
            ->when($veri['tarih_bas'] ?? null, fn ($q, $t) => $q->whereDate('islem_tarihi', '>=', $t))
            ->when($veri['tarih_bit'] ?? null, fn ($q, $t) => $q->whereDate('islem_tarihi', '<=', $t))
            ->when(trim((string) ($veri['ara'] ?? '')) !== '', fn ($q) => $this->aramaUygula($q, (string) $veri['ara']))
            ->when($guncel, fn ($q) => $this->guncelDonem($q, (int) $request->user()->hane_id));
        $islemler = $sorgu->with('ekstre:id,kesim_tarihi')->orderByDesc('islem_tarihi')->orderByDesc('id')->limit(1000)->get();

        $haric = $islemler->filter(fn (KartIslemi $i): bool => $i->tur->giderYonu() === 0);

        return response()->json([
            'islemler' => $islemler->map(fn (KartIslemi $i): array => [...$i->ozet(), 'ekstre_donemi' => $i->ekstre?->donemEtiketi()]),
            'toplam' => [
                'gider' => Kurus::tl($islemler->reduce(fn (int $t, KartIslemi $i): int => $t + $this->rapor->giderEtkisi($i), 0)),
                'gidere_girmeyen' => Kurus::tl($haric->reduce(fn (int $t, KartIslemi $i): int => $t + Kurus::kurus($i->tutar), 0)),
                'adet' => $islemler->count(),
                // Kart başına (grup başlığı): gider, satır sayısı, ekstre dönemi
                'kartlar' => $islemler->groupBy('kart_id')->map(fn ($grup, $kartId): array => [
                    'kart_id' => (int) $kartId,
                    'gider' => Kurus::tl($grup->reduce(fn (int $t, KartIslemi $i): int => $t + $this->rapor->giderEtkisi($i), 0)),
                    'adet' => $grup->count(),
                    // Grup tek ekstre dönemindense etiketi; birden çok dönem varsa (tüm dönemler) boş
                    'ekstre_donemi' => ($donemler = $grup->map(fn (KartIslemi $i): ?string => $i->ekstre?->donemEtiketi())->filter()->unique()->values())->count() === 1 ? $donemler->first() : null,
                ])->values(),
            ],
        ]);
    }

    /**
     * Güncel ekstre dönemi: her kartın en yeni onaylı ekstresinin satırları + o kartın henüz ekstreye girmemiş
     * (son kesimden sonraki) elle satırları.
     *
     * @param  Builder<KartIslemi>  $sorgu
     */
    private function guncelDonem($sorgu, int $haneId): void
    {
        $son = Ekstre::where('hane_id', $haneId)->where('durum', 'onaylandi')->orderByDesc('kesim_tarihi')->orderByDesc('id')
            ->get(['id', 'kart_id', 'kesim_tarihi'])->unique('kart_id')->keyBy('kart_id');
        $sorgu->where(function ($q) use ($son, $haneId): void {
            $q->whereIn('kart_islemleri.ekstre_id', $son->pluck('id')->all());
            foreach (Kart::where('hane_id', $haneId)->pluck('id') as $kartId) {
                $esik = $son[$kartId]->kesim_tarihi ?? null;
                $q->orWhere(fn ($e) => $e->where('kart_islemleri.kart_id', $kartId)->whereNull('kart_islemleri.ekstre_id')
                    ->when($esik, fn ($x) => $x->whereDate('islem_tarihi', '>', $esik->toDateString())));
            }
        });
    }

    /**
     * İşleme kategori ata (kategori_id null: kategorisiz); açıklaması aynı olan hanenin bütün kart satırları da aynı
     * kategoriye geçer ve açıklama kural olarak kaydedilir (sonraki ekstrelerde otomatik).
     */
    public function kategori(Request $request, KartIslemi $islem, KategoriAtayici $atayici): JsonResponse
    {
        Gate::authorize('hane-kaydi', $islem);
        $veri = $request->validate(['kategori_id' => ['nullable', 'integer']]);
        $kategori = null;
        if (($veri['kategori_id'] ?? null) !== null) {
            $kategori = HarcamaKategorisi::where('hane_id', $islem->hane_id)->find($veri['kategori_id']);
            if ($kategori === null) {
                throw ValidationException::withMessages(['kategori_id' => 'Kategori bulunamadı.']);
            }
        }
        $sayi = $atayici->ata($islem, $kategori);

        return response()->json([
            'islem' => $islem->fresh()->ozet(),
            'guncellenen' => $sayi,
            'kategori_ad' => $kategori?->ad,
            'message' => $kategori
                ? ($sayi > 1 ? "{$sayi} işlem \"{$kategori->ad}\" olarak işaretlendi." : "İşlem \"{$kategori->ad}\" olarak işaretlendi.")
                : ($sayi > 1 ? "{$sayi} işlemin kategorisi kaldırıldı." : 'İşlemin kategorisi kaldırıldı.'),
        ]);
    }

    /**
     * Arama: her kelime (AND) açıklamada, kategori adında ya da kart adında geçer (LIKE, harf duyarsız) veya tutar
     * biçimindeyse (1.250,50) tutara eşittir.
     *
     * @param  Builder<KartIslemi>  $sorgu
     */
    private function aramaUygula($sorgu, string $ara): void
    {
        foreach (preg_split('/\s+/u', trim($ara), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kelime) {
            $desen = '%'.addcslashes($kelime, '%_\\').'%';
            $tutar = $this->tutarKelimesi($kelime);
            $sorgu->where(function ($q) use ($desen, $tutar): void {
                $q->where('kart_islemleri.aciklama', 'like', $desen)
                    ->orWhereHas('kategori', fn ($k) => $k->where('ad', 'like', $desen))
                    ->orWhereHas('kart', fn ($k) => $k->where('ad', 'like', $desen));
                if ($tutar !== null) {
                    $q->orWhere('kart_islemleri.tutar', $tutar);
                }
            });
        }
    }

    /** "1.250,50", "1250.5", "250" → "1250.50"; tutar gibi görünmüyorsa null */
    private function tutarKelimesi(string $kelime): ?string
    {
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $kelime)) {
            $kelime = str_replace(',', '.', str_replace('.', '', $kelime));
        } elseif (preg_match('/^\d+([.,]\d{1,2})?$/', $kelime)) {
            $kelime = str_replace(',', '.', $kelime);
        } else {
            return null;
        }

        return Kurus::decimal(Kurus::kurus($kelime));
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
