<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\KurtarmaKodlari;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yönetici kurtarma kodları: kalan sayı ve yeniden üretim (mevcut şifre ister; kodlar yalnız bu yanıtta bir kez görünür).
 */
class KurtarmaKodlariController extends Controller
{
    public function __construct(private KurtarmaKodlari $kodlar) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->yonetici, 403);

        return response()->json(['kalan' => $this->kodlar->kalan($request->user()), 'adet' => KurtarmaKodlari::ADET]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->yonetici, 403);
        $request->validate(['sifre' => ['required', 'current_password:web']], [], ['sifre' => 'şifre']);

        return response()->json([
            'kodlar' => $this->kodlar->uret($request->user()),
            'message' => 'Kodlar üretildi. Bir daha gösterilmez; güvenli bir yere yazın. Eski kodlar geçersiz oldu.',
        ], 201);
    }
}
