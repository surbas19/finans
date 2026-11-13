<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * SPA girişi: Sanctum oturum çerezi (finans_oturum). Kayıt yok; kullanıcıyı yönetici ekler.
 */
class GirisController extends Controller
{
    public function giris(Request $request): JsonResponse
    {
        // Oturum yalnızca arayüzden (SANCTUM_STATEFUL_DOMAINS) gelen istekte açılır
        abort_unless($request->hasSession(), 400, 'Giriş yalnızca uygulamanın kendi sayfasından yapılabilir.');
        $veri = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'sifre' => ['required', 'string', 'max:255'],
            'hatirla' => ['sometimes', 'boolean'],
        ]);

        $bilgi = ['email' => mb_strtolower(trim($veri['email'])), 'password' => $veri['sifre'], 'aktif' => true];
        if (! Auth::guard('web')->attempt($bilgi, (bool) ($veri['hatirla'] ?? false))) {
            throw ValidationException::withMessages(['email' => 'E-posta veya şifre hatalı.']);
        }
        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['son_giris' => now()])->save();

        return response()->json(['kullanici' => $user->oturumBilgisi()]);
    }

    public function ben(Request $request): JsonResponse
    {
        return response()->json(['kullanici' => $request->user()->oturumBilgisi()]);
    }

    public function cikis(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Çıkış yapıldı.']);
    }

    public function sifre(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'mevcut_sifre' => ['required', 'current_password:web'],
            'yeni_sifre' => ['required', 'confirmed', Password::defaults()],
        ], [], ['mevcut_sifre' => 'mevcut şifre', 'yeni_sifre' => 'yeni şifre']);

        $request->user()->forceFill(['password' => $veri['yeni_sifre']])->save();
        // Öbür cihazlardaki oturumlar kapanır
        Auth::guard('web')->logoutOtherDevices($veri['yeni_sifre']);

        return response()->json(['message' => 'Şifreniz değiştirildi.']);
    }
}
