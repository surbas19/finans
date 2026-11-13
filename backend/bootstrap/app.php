<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // CSRF yalnızca köken denetimiyle (Sec-Fetch-Site: same-origin); XSRF çerezi yazılmaz. Okul sisteminin
        // .yoklm.com çerezleri bu adrese de geldiği için aynı adlı XSRF çerezi çakışırdı (CLAUDE.md → Çerez / CSRF).
        $middleware->preventRequestForgery(originOnly: true);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
