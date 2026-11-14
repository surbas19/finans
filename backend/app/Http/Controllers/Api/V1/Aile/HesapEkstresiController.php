<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\HesapEkstresi;
use App\Services\AIStatement\EkstreAiIsleyici;
use App\Services\AIStatement\OpenAIClient;
use App\Services\AIStatement\PdfUploadService;
use App\Services\AIStatement\YapayZekaHatasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Banka hesabı ekstresi (kart değil): yalnızca yapay zekâ analizi ve önizleme; Bütçe'ye aktarılmaz. PDF yalnızca
 * analiz süresince tutulur. Her yükleme kullanıcının açık onayıyla (PDF OpenAI'ye gider).
 */
class HesapEkstresiController extends Controller
{
    public function __construct(private EkstreAiIsleyici $isleyici, private PdfUploadService $yukleme) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'hesap_ekstreleri' => HesapEkstresi::hanenin($request->user())->orderByDesc('id')->limit(200)->get()
                ->map(fn (HesapEkstresi $h): array => [...$h->ozet(), 'ai_calisma' => $this->isleyici->son($h)?->ozet()]),
        ]);
    }

    public function store(Request $request, OpenAIClient $istemci): JsonResponse
    {
        $mb = (int) config('ekstre.max_mb');
        $request->validate([
            'dosya' => ['required', 'file', 'max:'.($mb * 1024), 'mimetypes:application/pdf'],
            'onay' => ['accepted'],
        ], [
            'dosya.mimetypes' => 'Yalnızca PDF yüklenebilir.', 'dosya.max' => "PDF en çok {$mb} MB olabilir.",
            'onay.accepted' => EkstreAnalizController::ONAY_MESAJI,
        ], ['dosya' => 'dosya']);
        if (! $istemci->acikMi()) {
            throw ValidationException::withMessages(['dosya' => YapayZekaHatasi::kapali()->getMessage()]);
        }
        $user = $request->user();
        $pdf = $this->yukleme->oku($request->file('dosya'));
        $onceki = HesapEkstresi::where('hane_id', $user->hane_id)->where('dosya_ozeti', $pdf['ozet'])->first();
        if ($onceki) {
            throw ValidationException::withMessages(['dosya' => 'Bu dosya '.$onceki->created_at->format('d.m.Y').' tarihinde yüklendi; aynı ekstre ikinci kez eklenmez.']);
        }
        $yol = $this->yukleme->sakla($user, $pdf['icerik'], 'hesap-ekstreleri');
        try {
            [$hesap, $calisma] = DB::transaction(function () use ($user, $pdf, $yol): array {
                $hesap = HesapEkstresi::create([
                    'hane_id' => $user->hane_id, 'durum' => 'analiz', 'dosya_yolu' => $yol, 'dosya_adi' => $pdf['ad'],
                    'dosya_boyutu' => $pdf['boyut'], 'dosya_ozeti' => $pdf['ozet'], 'olusturan_id' => $user->id,
                ]);

                return [$hesap, $this->isleyici->baslat($hesap, $user)];
            });
        } catch (Throwable $e) {
            $this->yukleme->sil($yol);
            throw $e;
        }
        $this->isleyici->arkaPlandaBaslat($calisma);

        return response()->json([
            'hesap_ekstresi' => $hesap->ozet(),
            'calisma' => $calisma->ozet(),
            'message' => 'PDF yüklendi; yapay zekâ analizi başladı (yalnızca önizleme, Bütçe\'ye aktarılmaz).',
        ], 201);
    }

    public function show(HesapEkstresi $hesap): JsonResponse
    {
        Gate::authorize('hane-kaydi', $hesap);
        $calisma = $this->isleyici->son($hesap);
        if ($calisma?->etkinMi()) {
            $calisma = $this->isleyici->ilerlet($calisma);
            $hesap->refresh();
        }

        return response()->json([
            'hesap_ekstresi' => $hesap->ozet(),
            'calisma' => $calisma?->ozet(),
            'ai_cikti' => $hesap->ai_cikti,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(HesapEkstresi $hesap): JsonResponse
    {
        Gate::authorize('hane-kaydi', $hesap);
        if (($calisma = $this->isleyici->etkin($hesap)) !== null) {
            $this->isleyici->iptal($calisma);
        }
        $yol = $hesap->dosya_yolu;
        $hesap->delete();
        $this->yukleme->sil($yol);

        return response()->json(['message' => 'Banka hesabı ekstresi silindi.']);
    }
}
