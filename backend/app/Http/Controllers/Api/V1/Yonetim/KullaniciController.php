<?php

namespace App\Http\Controllers\Api\V1\Yonetim;

use App\Http\Controllers\Controller;
use App\Models\Hane;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Kullanıcılar (yalnızca yönetici). Kayıt kapalı; kullanıcıyı yönetici ekler. Kişinin portföyü burada görünmez.
 */
class KullaniciController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('yonetici');

        return response()->json([
            'kullanicilar' => User::with('hane')->orderBy('ad')->orderBy('soyad')->get()->map(fn (User $u): array => [
                ...$u->oturumBilgisi(),
                'aktif' => $u->aktif,
                'son_giris' => $u->son_giris?->toIso8601String(),
                'hane' => $u->hane ? ['id' => $u->hane->id, 'ad' => $u->hane->ad] : null,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('yonetici');
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:60'],
            'soyad' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'sifre' => ['required', Password::defaults()],
            'yonetici' => ['sometimes', 'boolean'],
        ], [], ['sifre' => 'şifre']);

        $user = User::create([
            'ad' => trim($veri['ad']),
            'soyad' => trim($veri['soyad']),
            'email' => mb_strtolower(trim($veri['email'])),
            'password' => $veri['sifre'],
            'yonetici' => (bool) ($veri['yonetici'] ?? false),
            'aktif' => true,
        ]);

        return response()->json(['kullanici' => $user->oturumBilgisi(), 'message' => 'Kullanıcı eklendi.'], 201);
    }

    /**
     * `hane`: kullanıcıyı bağlanacak hanenin kimliği ya da "yeni" (kendi başına yeni hane). Kurallar HaneYonetimi'nde.
     */
    public function update(Request $request, User $kullanici, HaneYonetimi $haneler): JsonResponse
    {
        Gate::authorize('yonetici');
        $veri = $request->validate([
            'ad' => ['sometimes', 'required', 'string', 'max:60'],
            'soyad' => ['sometimes', 'required', 'string', 'max:60'],
            'aktif' => ['sometimes', 'boolean'],
            'yonetici' => ['sometimes', 'boolean'],
            'sifre' => ['sometimes', 'nullable', Password::defaults()],
            'hane' => ['sometimes', 'required', function (string $alan, mixed $deger, Closure $hata): void {
                if ($deger !== 'yeni' && ! (is_numeric($deger) && Hane::whereKey((int) $deger)->exists())) {
                    $hata('Hane bulunamadı.');
                }
            }],
        ], [], ['sifre' => 'şifre', 'hane' => 'hane']);

        if (array_key_exists('hane', $veri)) {
            $haneler->tasi($kullanici, $veri['hane'] === 'yeni' ? null : Hane::findOrFail((int) $veri['hane']));
            $kullanici->refresh();
        }

        $kendisi = $kullanici->is($request->user());
        if ($kendisi && ((isset($veri['aktif']) && ! $veri['aktif']) || (isset($veri['yonetici']) && ! $veri['yonetici']))) {
            throw ValidationException::withMessages(['aktif' => 'Kendi hesabınızı kapatamaz, yöneticiliğinizi kaldıramazsınız.']);
        }

        $kullanici->fill(collect($veri)->only(['ad', 'soyad', 'aktif', 'yonetici'])->all());
        if (! empty($veri['sifre'])) {
            $kullanici->password = $veri['sifre'];
        }
        $kullanici->save();
        if (! $kullanici->aktif || ! empty($veri['sifre'])) {
            // Kapatılan ya da şifresi değişen kişinin açık oturumları kapanır
            DB::table('sessions')->where('user_id', $kullanici->id)->delete();
            $kullanici->forceFill(['remember_token' => null])->save();
        }

        return response()->json(['kullanici' => $kullanici->oturumBilgisi(), 'message' => 'Kullanıcı güncellendi.']);
    }
}
