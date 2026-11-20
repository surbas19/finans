<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KurtarmaKodlari;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as SifreKurali;
use Illuminate\Validation\ValidationException;

/**
 * Şifre unutulduğunda giriş yapmadan sıfırlama: e-postayla bağlantı (herkes) ve kurtarma kodu (yalnız yönetici).
 * Hesap varlığı sızdırılmaz (yanıtlar sabit), uçlar `throttle:giris` ile sınırlıdır. Başarılı sıfırlamada kişinin tüm
 * oturumları ve "hatırla" çerezi kapanır.
 */
class SifreController extends Controller
{
    private const GENEL_HATA = 'Bağlantı geçersiz ya da süresi dolmuş. Yeni bir bağlantı isteyin.';

    public function unuttum(Request $request): JsonResponse
    {
        $veri = $request->validate(['email' => ['required', 'email', 'max:255']]);
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            // Sahte "gönderildi" denmez: e-posta ayarı yoksa kullanıcı yöneticiye yönlendirilir
            return response()->json(['message' => 'E-posta gönderimi ayarlı değil; yöneticiye başvurun.'], 503);
        }
        // Yalnız etkin kullanıcıya gider; aracı 60 sn'de bir isteği kendisi sınırlar. Yanıt her durumda aynıdır.
        Password::broker()->sendResetLink(['email' => mb_strtolower(trim($veri['email'])), 'aktif' => true]);

        return response()->json(['message' => 'Bu e-posta kayıtlıysa şifre sıfırlama bağlantısı gönderildi. Gelen kutunuzu ve istenmeyen klasörünü kontrol edin.']);
    }

    public function sifirla(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'yeni_sifre' => ['required', 'confirmed', SifreKurali::defaults()],
        ], [], ['yeni_sifre' => 'yeni şifre']);

        $durum = Password::broker()->reset(
            ['email' => mb_strtolower(trim($veri['email'])), 'password' => $veri['yeni_sifre'], 'token' => $veri['token'], 'aktif' => true],
            function (User $user, string $sifre): void {
                $this->sifreyiDegistir($user, $sifre);
                event(new PasswordReset($user));
            },
        );
        if ($durum !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => self::GENEL_HATA]);
        }

        return response()->json(['message' => 'Şifreniz değişti. Yeni şifrenizle giriş yapın.']);
    }

    /** Kurtarma koduyla sıfırlama (yalnız etkin yönetici); hata her durumda aynı mesaj */
    public function kodla(Request $request, KurtarmaKodlari $kodlar): JsonResponse
    {
        $veri = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'kod' => ['required', 'string', 'max:64'],
            'yeni_sifre' => ['required', 'confirmed', SifreKurali::defaults()],
        ], [], ['yeni_sifre' => 'yeni şifre']);

        $user = User::where('email', mb_strtolower(trim($veri['email'])))->where('aktif', true)->where('yonetici', true)->first();
        // Kullanıcı bulunmasa da aynı işi yap (zamanlama farkı hesap varlığını sızdırmasın)
        if ($user === null) {
            $kodlar->ozet($veri['kod']);
            $gecerli = false;
        } else {
            $gecerli = $kodlar->kullan($user, $veri['kod']);
        }
        if (! $gecerli) {
            throw ValidationException::withMessages(['kod' => 'E-posta ya da kurtarma kodu hatalı.']);
        }
        DB::transaction(function () use ($user, $veri): void {
            $this->sifreyiDegistir($user, $veri['yeni_sifre']);
            $user->forceFill(['kurtarma_kullanildi_at' => now()])->save();
        });

        return response()->json(['message' => 'Şifreniz değişti. Giriş yaptıktan sonra Profil\'den yeni kurtarma kodları üretmeyi unutmayın.']);
    }

    /** Şifreyi yazar; kişinin tüm oturumlarını ve "hatırla" çerezini kapatır */
    private function sifreyiDegistir(User $user, string $sifre): void
    {
        $user->forceFill(['password' => $sifre, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
