<?php

use App\Http\Controllers\Api\V1\Aile\BankaController;
use App\Http\Controllers\Api\V1\Aile\EkstreController;
use App\Http\Controllers\Api\V1\Aile\GelirController;
use App\Http\Controllers\Api\V1\Aile\GelirKaynagiController;
use App\Http\Controllers\Api\V1\Aile\HaneController as AileHaneController;
use App\Http\Controllers\Api\V1\Aile\KartController;
use App\Http\Controllers\Api\V1\Aile\KartIslemiController;
use App\Http\Controllers\Api\V1\Aile\KategoriController;
use App\Http\Controllers\Api\V1\Aile\OzetController as AileOzetController;
use App\Http\Controllers\Api\V1\GirisController;
use App\Http\Controllers\Api\V1\HesapController;
use App\Http\Controllers\Api\V1\IslemController;
use App\Http\Controllers\Api\V1\IzlemeController;
use App\Http\Controllers\Api\V1\OzetController;
use App\Http\Controllers\Api\V1\PerformansController;
use App\Http\Controllers\Api\V1\SurumController;
use App\Http\Controllers\Api\V1\VarlikController;
use App\Http\Controllers\Api\V1\Yonetim\HaneController as YonetimHaneController;
use App\Http\Controllers\Api\V1\Yonetim\KaynakController;
use App\Http\Controllers\Api\V1\Yonetim\KullaniciController;
use App\Http\Middleware\HaneyiHazirla;
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
        Route::post('varliklar/toplu', [VarlikController::class, 'toplu'])->middleware('throttle:kaynak')->name('varliklar.toplu');
        Route::get('varliklar/{varlik}', [VarlikController::class, 'show'])->name('varliklar.show');
        Route::get('varliklar/{varlik}/gecmis', [VarlikController::class, 'gecmis'])->name('varliklar.gecmis');
        Route::put('varliklar/{varlik}/fiyat', [VarlikController::class, 'elleFiyat'])->name('varliklar.fiyat');

        Route::get('izleme', [IzlemeController::class, 'index'])->name('izleme.index');
        Route::post('izleme', [IzlemeController::class, 'store'])->name('izleme.store');
        Route::put('izleme/sira', [IzlemeController::class, 'sirala'])->name('izleme.sira');
        Route::delete('izleme/{varlik}', [IzlemeController::class, 'destroy'])->name('izleme.destroy');

        // Aile finansı: hanenin ortak kayıtları (hanesi olmayana ilk girişte tek kişilik hane kurulur)
        Route::prefix('aile')->name('aile.')->middleware(HaneyiHazirla::class)->group(function (): void {
            Route::get('hane', [AileHaneController::class, 'goster'])->name('hane');
            Route::put('hane', [AileHaneController::class, 'guncelle'])->name('hane.guncelle');
            Route::post('uyeler', [AileHaneController::class, 'uyeEkle'])->name('uyeler.store');
            Route::put('uyeler/{uye}', [AileHaneController::class, 'uyeGuncelle'])->name('uyeler.update');
            Route::delete('uyeler/{uye}', [AileHaneController::class, 'uyeSil'])->name('uyeler.destroy');

            Route::apiResource('gelir-kaynaklari', GelirKaynagiController::class)->except('show')->parameters(['gelir-kaynaklari' => 'kaynak']);
            Route::apiResource('gelirler', GelirController::class)->except('show')->parameters(['gelirler' => 'gelir']);

            Route::apiResource('bankalar', BankaController::class)->except('show')->parameters(['bankalar' => 'banka']);
            Route::apiResource('kartlar', KartController::class)->parameters(['kartlar' => 'kart']);
            Route::apiResource('kategoriler', KategoriController::class)->except('show')->parameters(['kategoriler' => 'kategori']);
            Route::post('kategoriler/{kategori}/kurallar', [KategoriController::class, 'kuralEkle'])->name('kategoriler.kurallar.store');
            Route::delete('kategori-kurallari/{kural}', [KategoriController::class, 'kuralSil'])->name('kategori-kurallari.destroy');
            Route::apiResource('kart-islemleri', KartIslemiController::class)->except('show')->parameters(['kart-islemleri' => 'islem']);
            Route::get('ekstreler', [EkstreController::class, 'index'])->name('ekstreler.index');
            Route::post('ekstreler', [EkstreController::class, 'store'])->middleware('throttle:ekstre')->name('ekstreler.store');
            Route::get('ekstreler/{ekstre}', [EkstreController::class, 'show'])->name('ekstreler.show');
            Route::put('ekstreler/{ekstre}', [EkstreController::class, 'update'])->name('ekstreler.update');
            Route::delete('ekstreler/{ekstre}', [EkstreController::class, 'destroy'])->name('ekstreler.destroy');
            Route::get('ekstreler/{ekstre}/dosya', [EkstreController::class, 'dosya'])->name('ekstreler.dosya');
            Route::put('ekstreler/{ekstre}/satirlar/{islem}', [EkstreController::class, 'satirGuncelle'])->name('ekstreler.satirlar.update');
            Route::post('ekstreler/{ekstre}/onayla', [EkstreController::class, 'onayla'])->name('ekstreler.onayla');
            Route::get('ozet', AileOzetController::class)->name('ozet');
        });

        Route::prefix('yonetim')->name('yonetim.')->group(function (): void {
            Route::get('haneler', [YonetimHaneController::class, 'index'])->name('haneler.index');
            Route::get('kullanicilar', [KullaniciController::class, 'index'])->name('kullanicilar.index');
            Route::post('kullanicilar', [KullaniciController::class, 'store'])->name('kullanicilar.store');
            Route::put('kullanicilar/{kullanici}', [KullaniciController::class, 'update'])->name('kullanicilar.update');
            Route::get('kaynaklar', [KaynakController::class, 'index'])->name('kaynaklar.index');
            Route::post('kaynaklar/guncelle', [KaynakController::class, 'guncelle'])->middleware('throttle:kaynak')->name('kaynaklar.guncelle');
        });
    });
});
