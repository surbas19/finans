<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Http\Controllers\Controller;
use App\Models\Ekstre;
use App\Services\AIStatement\EkstreAiIsleyici;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kart ekstresinin yapay zekâ analizi (yalnızca incelemedeki ekstre, her seferinde açık onayla): başlat (202; gönderim
 * yanıttan sonra), durum (ön yüz birkaç saniyede bir sorar; çalışma ilerletilir), iptal ve maskeli JSON görünümü.
 * Sonuç her zaman taslaktır; aktarım kullanıcının onayıyla mevcut onay akışından yapılır.
 */
class EkstreAnalizController extends Controller
{
    /** Onay kutusunun metni (çalışma kaydına sürümü yazılır: EkstreAiIsleyici::ONAY_SURUMU) */
    public const ONAY_MESAJI = "PDF dosyasının (adınız, adresiniz ve maskeli kart numaranız dahil) yapay zekâ analizi için OpenAI'ye gönderilmesini onaylayın.";

    public function __construct(private EkstreAiIsleyici $isleyici) {}

    public function baslat(Request $request, Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $request->validate(['onay' => ['accepted']], ['onay.accepted' => self::ONAY_MESAJI]);
        $calisma = $this->isleyici->baslat($ekstre, $request->user());
        $this->isleyici->arkaPlandaBaslat($calisma);

        return response()->json([
            'calisma' => $calisma->ozet(),
            'message' => 'Yapay zekâ analizi başladı; sayfa sayısına göre birkaç dakika sürebilir.',
        ], 202);
    }

    public function durum(Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $calisma = $this->isleyici->son($ekstre);
        if ($calisma?->etkinMi()) {
            $calisma = $this->isleyici->ilerlet($calisma);
        }

        return response()->json(['calisma' => $calisma?->ozet(), 'ekstre' => $ekstre->fresh()->ozet()]);
    }

    public function iptal(Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        $calisma = $this->isleyici->etkin($ekstre);
        if ($calisma === null) {
            throw ValidationException::withMessages(['ekstre' => 'Süren bir yapay zekâ analizi yok.']);
        }

        return response()->json(['calisma' => $this->isleyici->iptal($calisma)?->ozet(), 'message' => 'Yapay zekâ analizi iptal edildi.']);
    }

    /** Maskeli standart JSON (kişisel veri maskelenmiş); yalnızca hane üyesine */
    public function json(Ekstre $ekstre): JsonResponse
    {
        Gate::authorize('hane-kaydi', $ekstre);
        if ($ekstre->ai_cikti === null) {
            abort(404);
        }

        return response()->json(['ai_cikti' => $ekstre->ai_cikti], 200, ['Cache-Control' => 'private, no-store']);
    }
}
