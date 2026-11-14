<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profil resmi. Arayüz resmi tarayıcıda kare kırpıp 256×256'ya küçültür (sunucuda GD gerekmez); burada yalnızca
 * doğrulanır ve özel diske yazılır. Resmi kendisi, aynı hanedekiler ve yönetici görür; öbürlerine yok gibi (404).
 */
class ProfilResmiController extends Controller
{
    public const DISK = 'local';

    private const TURLER = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function yukle(Request $request): JsonResponse
    {
        $request->validate([
            'resim' => [
                'required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:1024',
                'dimensions:min_width=32,min_height=32,max_width=2048,max_height=2048',
            ],
        ], [
            'resim.mimes' => 'Resim JPEG, PNG ya da WebP olmalı.',
            'resim.max' => 'Resim en çok 1 MB olabilir.',
            'resim.dimensions' => 'Resim 32 ile 2048 piksel arasında olmalı.',
        ], ['resim' => 'resim']);

        $user = $request->user();
        $dosya = $request->file('resim');
        $uzanti = $dosya->guessExtension();
        $uzanti = $uzanti === 'jpeg' ? 'jpg' : $uzanti;
        $yol = $dosya->storeAs('profil', $user->id.'-'.Str::random(20).'.'.$uzanti, self::DISK);
        $eski = $user->profil_resmi;
        $user->forceFill(['profil_resmi' => $yol])->save();
        if ($eski !== null) {
            Storage::disk(self::DISK)->delete($eski);
        }

        return response()->json(['kullanici' => $user->oturumBilgisi(), 'message' => 'Profil resmi kaydedildi.']);
    }

    public function sil(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->profil_resmi !== null) {
            Storage::disk(self::DISK)->delete($user->profil_resmi);
            $user->forceFill(['profil_resmi' => null])->save();
        }

        return response()->json(['kullanici' => $user->oturumBilgisi(), 'message' => 'Profil resmi kaldırıldı.']);
    }

    public function goster(Request $request, User $kullanici): StreamedResponse
    {
        $ben = $request->user();
        $gorebilir = $ben->id === $kullanici->id || $ben->ayniHanede($kullanici->id) || $ben->can('yonetici');
        $yol = $kullanici->profil_resmi;
        if (! $gorebilir || $yol === null || ! Storage::disk(self::DISK)->exists($yol)) {
            abort(404);
        }

        // Adres her yeni resimde değişir (?v=): uzun süre önbellekte kalabilir; yalnızca bu tarayıcıda
        return Storage::disk(self::DISK)->response($yol, null, [
            'Content-Type' => self::TURLER[pathinfo($yol, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
