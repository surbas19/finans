<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Finansal veri: en az 8 karakter, harf ve rakam
        Password::defaults(fn (): Password => Password::min(8)->max(100)->letters()->numbers());

        Gate::define('yonetici', fn (User $user): bool => $user->yonetici && $user->aktif);
        // Aile kayıtları (gelir, kart, ekstre, fatura…): yalnızca aynı hanenin kullanıcıları görür ve değiştirir
        Gate::define('hane-kaydi', fn (User $user, Model $kayit): bool => $user->hane_id !== null
            && (int) $kayit->getAttribute('hane_id') === $user->hane_id);

        // Aynı IP + e-posta dakikada 5; IP değiştirerek tek hesaba deneme saatte 20.
        RateLimiter::for('giris', fn (Request $request): array => [
            Limit::perMinute(5)->by($request->ip().'|'.mb_strtolower((string) $request->input('email'))),
            Limit::perHour(20)->by('email|'.mb_strtolower((string) $request->input('email'))),
        ]);
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        // Kaynağa giden istekler (kod ekleme, elle güncelleme) kaynağı yormasın
        RateLimiter::for('kaynak', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        // Ekstre PDF yükleme: ayrıştırma sunucuyu yorar
        RateLimiter::for('ekstre', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }
}
