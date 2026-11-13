<?php

use App\Http\Controllers\Api\V1\GirisController;
use App\Http\Controllers\Api\V1\HesapController;
use App\Http\Controllers\Api\V1\IslemController;
use App\Http\Controllers\Api\V1\IzlemeController;
use App\Http\Controllers\Api\V1\OzetController;
use App\Http\Controllers\Api\V1\PerformansController;
use App\Http\Controllers\Api\V1\SurumController;
use App\Http\Controllers\Api\V1\VarlikController;
use App\Http\Controllers\Api\V1\Yonetim\KaynakController;
use App\Http\Controllers\Api\V1\Yonetim\KullaniciController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::post('giris', [GirisController::class, 'giris'])->middleware('throttle:giris')->name('giris');
    // Girişsiz: giriş sayfasındaki açık uygulama da yeni sürümü fark etsin
    Route::get('surum', SurumController::class)->middleware('throttle:api')->name('surum');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('ben', [GirisController::class, 'ben'])->name('ben');
        Route::post('cikis', [GirisController::class, 'cikis'])->name('cikis');
        Route::put('ben/sifre', [GirisController::class, 'sifre'])->middleware('throttle:giris')->name('ben.sifre');

        Route::get('ozet', OzetController::class)->name('ozet');
        Route::get('ozet/gecmis', PerformansController::class)->name('ozet.gecmis');

        Route::put('hesaplar/sira', [HesapController::class, 'sirala'])->name('hesaplar.sira');
        Route::apiResource('hesaplar', HesapController::class)->parameters(['hesaplar' => 'hesap']);

        Route::post('islemler/toplu', [IslemController::class, 'toplu'])->name('islemler.toplu');
        Route::apiResource('islemler', IslemController::class)->except('show')->parameters(['islemler' => 'islem']);

        Route::get('varliklar', [VarlikController::class, 'index'])->name('varliklar.index');
        Route::post('varliklar', [VarlikController::class, 'store'])->middleware('throttle:kaynak')->name('varliklar.store');
        Route::get('varliklar/{varlik}', [VarlikController::class, 'show'])->name('varliklar.show');
        Route::get('varliklar/{varlik}/gecmis', [VarlikController::class, 'gecmis'])->name('varliklar.gecmis');
        Route::put('varliklar/{varlik}/fiyat', [VarlikController::class, 'elleFiyat'])->name('varliklar.fiyat');

        Route::get('izleme', [IzlemeController::class, 'index'])->name('izleme.index');
        Route::post('izleme', [IzlemeController::class, 'store'])->name('izleme.store');
        Route::put('izleme/sira', [IzlemeController::class, 'sirala'])->name('izleme.sira');
        Route::delete('izleme/{varlik}', [IzlemeController::class, 'destroy'])->name('izleme.destroy');

        Route::prefix('yonetim')->name('yonetim.')->group(function (): void {
            Route::get('kullanicilar', [KullaniciController::class, 'index'])->name('kullanicilar.index');
            Route::post('kullanicilar', [KullaniciController::class, 'store'])->name('kullanicilar.store');
            Route::put('kullanicilar/{kullanici}', [KullaniciController::class, 'update'])->name('kullanicilar.update');
            Route::get('kaynaklar', [KaynakController::class, 'index'])->name('kaynaklar.index');
            Route::post('kaynaklar/guncelle', [KaynakController::class, 'guncelle'])->middleware('throttle:kaynak')->name('kaynaklar.guncelle');
        });
    });
});
