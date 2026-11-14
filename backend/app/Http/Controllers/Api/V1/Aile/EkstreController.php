<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\EkstreDurumu;
use App\Enums\KartIslemTuru;
use App\Http\Controllers\Controller;
use App\Models\Ekstre;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Services\AIStatement\EkstreAiIsleyici;
use App\Services\AIStatement\OpenAIClient;
use App\Services\AIStatement\YapayZekaHatasi;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Support\Donem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kredi kartı ekstreleri: PDF yükleme → taslak satırlar → inceleme (düzelt / dışarıda bırak) → onay. PDF yalnızca
 * hane üyelerine, kimlik doğrulamalı uçtan açılır (açık adres yok).
 */
class EkstreController extends Controller
{
    public function __construct(private EkstreIceAktarici $aktarici, private EkstreAiIsleyici $isleyici) {}

    public function index(Request $request): JsonResponse
    {
        $veri = $request->validate(['kart_id' => ['nullable', 'integer']]);

        return response()->json([
            'ekstreler' => Ekstre::hanenin($request->user())->withCount('islemler')
                ->when($veri['kart_id'] ?? null, fn ($q, $id) => $q->where('kart_id', $id))
                ->orderByDesc('kesim_tarihi')->orderByDesc('id')->limit(500)->get()
                ->map(fn (Ekstre $e): array => [...$e->ozet(), 'satir_sayisi' => $e->islemler_count]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $mb = (int) config('ekstre.max_mb');
        $veri = $request->validate([
            'kart_id' => ['required', 'integer', Rule::exists('kartlar', 'id')->where('hane_id', $request->user()->hane_id)],
            // MIME sunucuda içerikten belirlenir (uzantı yetmez); imza ve içerik ayrıca PdfUploadService'te
            'dosya' => ['required', 'file', 'max:'.($mb * 1024), 'mimetypes:application/pdf'],
            // Kullanıcının açık onayı: PDF yapay zekâ analizi için OpenAI'ye gönderilir
            'yapay_zeka' => ['sometimes', 'boolean'],
        ], ['dosya.mimetypes' => 'Yalnızca PDF yüklenebilir.', 'dosya.max' => "PDF en çok {$mb} MB olabilir."], ['kart_id' => 'kart', 'dosya' => 'dosya']);
        $yapayZeka = (bool) ($veri['yapay_zeka'] ?? false);
        if ($yapayZeka && ! app(OpenAIClient::class)->acikMi()) {
            throw ValidationException::withMessages(['yapay_zeka' => YapayZekaHatasi::kapali()->getMessage()]);
        }

        $ekstre = $this->aktarici->yukle($request->user(), Kart::findOrFail($veri['kart_id']), $request->file('dosya'), metinsizKabul: $yapayZeka);
        $satirlar = $ekstre->islemler()->get();
        $calisma = null;
        if ($yapayZeka) {
            $calisma = $this->isleyici->baslat($ekstre, $request->user());
            $this->isleyici->arkaPlandaBaslat($calisma);
        }

        return response()->json([
            'ekstre' => $ekstre->ozet(),
            'calisma' => $calisma?->ozet(),
            'ozet' => [
                'satir' => $satirlar->count(),
                'okunamayan_satir' => $satirlar->filter(fn (KartIslemi $i): bool => ($i->okuma['alanlar'] ?? []) !== [])->count(),
                'tekrar' => $satirlar->whereNotNull('tekrar_islem_id')->count(),
            ],
            'message' => match (true) {
                $yapayZeka => 'PDF yüklendi; yapay zekâ analizi başladı'.($satirlar->isNotEmpty() ? " (PHP okuyucu {$satirlar->count()} satır buldu, karşılaştırılacak)" : '').'.',
                $satirlar->isEmpty() => 'PDF okundu ama işlem satırı bulunamadı; satırları elle ekleyebilirsiniz.',
                default => "PDF okundu: {$satirlar->count()} satır incelemeye hazır.",
            },
        ], 201);
    }

    public function show(Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $ekstre->load('kart');
        // Satırların fatura önerisi kesim ayını bu ekstreden okur (satır başına sorgu yok)
        $islemler = $ekstre->islemler()->get()->each(fn (KartIslemi $i) => $i->setRelation('ekstre', $ekstre));
        $tekrarlar = KartIslemi::whereIn('id', $islemler->pluck('tekrar_islem_id')->filter())->with('ekstre')->get()->keyBy('id');

        return response()->json([
            'ekstre' => $ekstre->ozet(),
            'kart' => $ekstre->kart->ozet(),
            'ai_acik' => app(OpenAIClient::class)->acikMi(),
            'ai_aktarim' => (bool) config('ekstre.ai_aktarim'),
            'ai_calisma' => $this->isleyici->son($ekstre)?->ozet(),
            'satirlar' => $islemler->map(fn (KartIslemi $i): array => [
                ...$i->ozet(),
                'tekrar' => $i->tekrar_islem_id && isset($tekrarlar[$i->tekrar_islem_id])
                    ? ['id' => $i->tekrar_islem_id, 'yer' => $tekrarlar[$i->tekrar_islem_id]->ekstre?->donemEtiketi() ? $tekrarlar[$i->tekrar_islem_id]->ekstre->donemEtiketi().' ekstresi' : 'elle girilen işlem']
                    : null,
            ]),
        ]);
    }

    /** Dönem bilgilerini düzeltme (taslakta) ve "ödendi" işareti (her zaman) */
    public function update(Request $request, Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $veri = $request->validate([
            'donem_baslangic' => ['sometimes', 'nullable', 'date'],
            'kesim_tarihi' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'son_odeme_tarihi' => ['sometimes', 'nullable', 'date'],
            'donem_borcu' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000000', 'decimal:0,2'],
            'asgari_odeme' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000000', 'decimal:0,2'],
            'odendi' => ['sometimes', 'boolean'],
        ], [], ['kesim_tarihi' => 'kesim tarihi', 'son_odeme_tarihi' => 'son ödeme tarihi', 'donem_borcu' => 'dönem borcu', 'asgari_odeme' => 'asgari ödeme']);
        if (array_diff(array_keys($veri), ['odendi']) !== []) {
            $this->analizSurmuyor($ekstre);
        }
        if ($ekstre->durum === EkstreDurumu::Onaylandi && array_diff(array_keys($veri), ['odendi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme']) !== []) {
            throw ValidationException::withMessages(['ekstre' => 'Onaylı ekstrenin kesim tarihi değişmez; ekstreyi silip yeniden yükleyin.']);
        }
        $this->aktarici->alanlariGuncelle($ekstre, $veri);

        return response()->json(['ekstre' => $ekstre->fresh()->ozet(), 'message' => 'Ekstre kaydedildi.']);
    }

    public function satirGuncelle(Request $request, Ekstre $ekstre, KartIslemi $islem): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        if ($islem->ekstre_id !== $ekstre->id) {
            abort(404);
        }
        if ($ekstre->durum === EkstreDurumu::Onaylandi) {
            throw ValidationException::withMessages(['islem' => 'Onaylı ekstrenin satırı İşlemler sayfasından düzeltilir.']);
        }
        $this->analizSurmuyor($ekstre);
        $veri = $request->validate([
            'dahil' => ['sometimes', 'boolean'],
            'islem_tarihi' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'aciklama' => ['sometimes', 'required', 'string', 'max:255'],
            'tutar' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000000000', 'decimal:0,2'],
            'tur' => ['sometimes', Rule::enum(KartIslemTuru::class)],
            'taksit_no' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'taksit_sayisi' => ['sometimes', 'nullable', 'integer', 'between:2,99'],
            'taksit_toplam' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000000000', 'decimal:0,2'],
            'kategori_id' => ['sometimes', 'nullable', 'integer', Rule::exists('harcama_kategorileri', 'id')->where('hane_id', $ekstre->hane_id)],
            // Fatura kararı: işaretle, sağlayıcı ve hizmet dönemi (boş = bilinmiyor)
            'fatura' => ['sometimes', 'nullable', 'array'],
            'fatura.secildi' => ['sometimes', 'boolean'],
            'fatura.saglayici_id' => ['sometimes', 'nullable', 'integer', Rule::exists('fatura_saglayicilari', 'id')->where('hane_id', $ekstre->hane_id)],
            'fatura.hizmet_donemi' => ['sometimes', 'nullable', 'regex:'.Donem::DESEN],
        ], ['fatura.hizmet_donemi.regex' => 'Hizmet dönemi YYYY-AA olmalı.'], [
            'islem_tarihi' => 'işlem tarihi', 'aciklama' => 'açıklama', 'tutar' => 'tutar', 'tur' => 'tür', 'fatura.saglayici_id' => 'sağlayıcı',
        ]);
        $no = $veri['taksit_no'] ?? $islem->taksit_no;
        $sayi = $veri['taksit_sayisi'] ?? $islem->taksit_sayisi;
        if (($no === null) !== ($sayi === null) || ($no !== null && $no > $sayi)) {
            throw ValidationException::withMessages(['taksit_no' => 'Taksit "kaçıncı / kaç" olarak ikisi birlikte girilir (ör. 3 / 6).']);
        }
        $this->aktarici->satirGuncelle($islem, $veri);

        return response()->json(['islem' => $islem->fresh()->ozet(), 'message' => 'Satır kaydedildi.']);
    }

    public function onayla(Request $request, Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $this->analizSurmuyor($ekstre);
        $rapor = $this->aktarici->onayla($ekstre, $request->user());

        return response()->json([
            'ekstre' => $ekstre->fresh()->ozet(),
            'rapor' => $rapor,
            'message' => "{$rapor['eklenen']} satır eklendi, {$rapor['atlanan']} satır dışarıda bırakıldı"
                .($rapor['tekrar'] > 0 ? " ({$rapor['tekrar']} tekrar)" : '')
                .($rapor['fatura'] > 0 ? ", {$rapor['fatura']} fatura bağlandı" : '').'.',
        ]);
    }

    /** Yapay zekâ analizi sürerken satırlar / dönem bilgileri değişmez, onaylanmaz (sonuç bunların üstüne yazılır) */
    private function analizSurmuyor(Ekstre $ekstre): void
    {
        if ($this->isleyici->etkin($ekstre) !== null) {
            throw ValidationException::withMessages(['ekstre' => 'Yapay zekâ analizi sürüyor; bitince düzeltip onaylayın (ya da analizi iptal edin).']);
        }
    }

    /** PDF yalnızca hane üyesine; tarayıcıda açılır, önbelleğe alınmaz */
    public function dosya(Ekstre $ekstre): StreamedResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        if ($ekstre->dosya_yolu === null || ! Storage::disk(EkstreIceAktarici::DISK)->exists($ekstre->dosya_yolu)) {
            abort(404);
        }

        return Storage::disk(EkstreIceAktarici::DISK)->response($ekstre->dosya_yolu, $ekstre->dosya_adi ?? 'ekstre.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function destroy(Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $this->aktarici->sil($ekstre);

        return response()->json(['message' => 'Ekstre, satırları ve PDF dosyası silindi.']);
    }
}
