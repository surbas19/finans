<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hesabı kapatılan kullanıcı, "hatırla" çerezi ya da açık oturumu geçerli olsa da içeri alınmaz (oturum sonlandırılır).
 */
class AktifKullanici
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->aktif) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }
            abort(401, 'Hesap kapalı.');
        }

        return $next($request);
    }
}
