<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Derlenmiş arayüz (frontend `npm run build` → resources/spa/index.html). API ile aynı kökten sunulur; böylece
 * oturum çerezi bu adrese özel kalır ve CSRF köken denetimiyle yapılır (CLAUDE.md → Çerez / CSRF).
 */
class SpaController extends Controller
{
    public function __invoke(): Response
    {
        $dosya = resource_path('spa/index.html');
        if (! is_file($dosya)) {
            return response('Arayüz derlenmemiş: frontend klasöründe "npm run build" çalıştırın (yerelde http://localhost:5174 kullanın).', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response((string) file_get_contents($dosya), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
