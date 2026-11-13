<?php

namespace App\Http\Controllers\Api\V1\Yonetim;

use App\Http\Controllers\Controller;
use App\Models\Hane;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Haneler (yalnızca yönetici): kullanıcıyı haneye bağlarken seçim listesi. Hanenin kayıtları burada görünmez.
 */
class HaneController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('yonetici');

        return response()->json([
            'haneler' => Hane::with(['kullanicilar' => fn ($q) => $q->orderBy('ad')])->orderBy('ad')->get()
                ->map(fn (Hane $h): array => [
                    'id' => $h->id,
                    'ad' => $h->ad,
                    'kullanicilar' => $h->kullanicilar->map(fn (User $u): string => trim($u->ad.' '.$u->soyad))->values(),
                ]),
        ]);
    }
}
