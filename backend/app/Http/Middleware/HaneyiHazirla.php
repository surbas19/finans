<?php

namespace App\Http\Middleware;

use App\Services\Aile\HaneYonetimi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aile uçlarında kullanıcının hanesi yoksa tek kişilik hane kurulur; böylece her aile kaydı bir haneye yazılır.
 */
class HaneyiHazirla
{
    public function __construct(private HaneYonetimi $haneler) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->hane_id === null) {
            $this->haneler->hazirla($user);
        }

        return $next($request);
    }
}
