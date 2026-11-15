<?php

use App\Http\Controllers\Api\V1\Aile\BankaController;
use App\Http\Controllers\Api\V1\Aile\DuzenliGiderController;
use App\Http\Controllers\Api\V1\Aile\EkstreAnalizController;
use App\Http\Controllers\Api\V1\Aile\EkstreController;
use App\Http\Controllers\Api\V1\Aile\FaturaController;
use App\Http\Controllers\Api\V1\Aile\GelirController;
use App\Http\Controllers\Api\V1\Aile\GelirKaynagiController;
use App\Http\Controllers\Api\V1\Aile\GelirTuruController;
use App\Http\Controllers\Api\V1\Aile\GiderKaynagiController;
use App\Http\Controllers\Api\V1\Aile\GrafikController;
use App\Http\Controllers\Api\V1\Aile\HaneController as AileHaneController;
use App\Http\Controllers\Api\V1\Aile\HesapEkstresiController;
use App\Http\Controllers\Api\V1\Aile\KartController;
use App\Http\Controllers\Api\V1\Aile\KartIslemiController;
use App\Http\Controllers\Api\V1\Aile\KategoriController;
use App\Http\Controllers\Api\V1\Aile\OzetController as AileOzetController;
use App\Http\Controllers\Api\V1\Aile\SaglayiciController;
use App\Http\Controllers\Api\V1\Aile\TasarrufController;
use App\Http\Controllers\Api\V1\Aile\TasarrufGunuController;
use App\Http\Controllers\Api\V1\Aile\TasarrufOzetController;
use App\Http\Controllers\Api\V1\Aile\ZekatAyarController;
use App\Http\Controllers\Api\V1\Aile\ZekatBorcController;
use App\Http\Controllers\Api\V1\Aile\ZekatController;
use App\Http\Controllers\Api\V1\Aile\ZekatDonemController;
use App\Http\Controllers\Api\V1\Aile\ZekatOdemeController;
use App\Http\Controllers\Api\V1\GirisController;
use App\Http\Controllers\Api\V1\HesapController;
use App\Http\Controllers\Api\V1\IslemController;
use App\Http\Controllers\Api\V1\IzlemeController;
use App\Http\Controllers\Api\V1\OzetController;
use App\Http\Controllers\Api\V1\PerformansController;
use App\Http\Controllers\Api\V1\ProfilResmiController;
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
        Route::post('ben/resim', [ProfilResmiController::class, 'yukle'])->name('ben.resim');
        Route::delete('ben/resim', [ProfilResmiController::class, 'sil'])->name('ben.resim.sil');
        Route::get('kullanicilar/{kullanici}/resim', [ProfilResmiController::class, 'goster'])->name('kullanicilar.resim');

        Route::get('ozet', OzetController::class)->name('ozet');
        Route::get('ozet/gecmis', PerformansController::class)->name('ozet.gecmis');

        Route::put('hesaplar/sira', [HesapController::class, 'sirala'])->name('hesaplar.sira');
        Route::put('hesaplar/{hesap}/pozisyonlar/{varlik}', [HesapController::class, 'pozisyonDahil'])->name('hesaplar.pozisyon.dahil');
        Route::post('hesaplar/{hesap}/pozisyonlar/{varlik}/aktar', [HesapController::class, 'pozisyonAktar'])->name('hesaplar.pozisyon.aktar');
        Route::apiResource('hesaplar', HesapController::class)->parameters(['hesaplar' => 'hesap']);

        Route::post('islemler/toplu', [IslemController::class, 'toplu'])->name('islemler.toplu');
        Route::put('islemler/{islem}/dahil', [IslemController::class, 'dahil'])->name('islemler.dahil');
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
            Route::apiResource('gelir-turleri', GelirTuruController::class)->except('show')->parameters(['gelir-turleri' => 'tur']);

            Route::apiResource('gider-kaynaklari', GiderKaynagiController::class)->except('show')->parameters(['gider-kaynaklari' => 'kaynak']);
            Route::apiResource('duzenli-giderler', DuzenliGiderController::class)->except('show')->parameters(['duzenli-giderler' => 'gider']);

            Route::get('tasarruf-ozeti', TasarrufOzetController::class)->name('tasarruf.ozeti');
            Route::get('grafikler', GrafikController::class)->name('grafikler');
            Route::get('tasarruflar', [TasarrufController::class, 'index'])->name('tasarruflar.index');
            Route::post('tasarruflar', [TasarrufController::class, 'store'])->name('tasarruflar.store');
            Route::delete('tasarruflar/{tasarruf}', [TasarrufController::class, 'destroy'])->name('tasarruflar.destroy');
            Route::apiResource('tasarruf-gunleri', TasarrufGunuController::class)->except('show')->parameters(['tasarruf-gunleri' => 'gun']);

            Route::apiResource('bankalar', BankaController::class)->except('show')->parameters(['bankalar' => 'banka']);
            Route::apiResource('kartlar', KartController::class)->parameters(['kartlar' => 'kart']);
            Route::apiResource('kategoriler', KategoriController::class)->except('show')->parameters(['kategoriler' => 'kategori']);
            Route::post('kategoriler/{kategori}/kurallar', [KategoriController::class, 'kuralEkle'])->name('kategoriler.kurallar.store');
            Route::delete('kategori-kurallari/{kural}', [KategoriController::class, 'kuralSil'])->name('kategori-kurallari.destroy');
            Route::apiResource('kart-islemleri', KartIslemiController::class)->except('show')->parameters(['kart-islemleri' => 'islem']);
            Route::put('kart-islemleri/{islem}/kategori', [KartIslemiController::class, 'kategori'])->name('kart-islemleri.kategori');
            Route::get('ekstreler', [EkstreController::class, 'index'])->name('ekstreler.index');
            Route::post('ekstreler', [EkstreController::class, 'store'])->middleware('throttle:ekstre')->name('ekstreler.store');
            Route::post('ekstreler/elle', [EkstreController::class, 'elle'])->name('ekstreler.elle');
            // Dış yapay zekâ: istem kopyala → JSON yapıştır ({ekstre} rotalarından önce)
            Route::get('ekstre-istemi', [EkstreController::class, 'istem'])->name('ekstre-istemi');
            Route::post('ekstreler/json-ile', [EkstreController::class, 'jsonIle'])->middleware('throttle:ekstre')->name('ekstreler.json-ile');
            Route::get('ekstreler/{ekstre}', [EkstreController::class, 'show'])->name('ekstreler.show');
            Route::put('ekstreler/{ekstre}', [EkstreController::class, 'update'])->name('ekstreler.update');
            Route::delete('ekstreler/{ekstre}', [EkstreController::class, 'destroy'])->name('ekstreler.destroy');
            Route::get('ekstreler/{ekstre}/dosya', [EkstreController::class, 'dosya'])->name('ekstreler.dosya');
            Route::put('ekstreler/{ekstre}/satirlar/{islem}', [EkstreController::class, 'satirGuncelle'])->name('ekstreler.satirlar.update');
            // Yapay zekâ analizi: başlat (onaylı, 202), durum (yoklama), iptal, maskeli JSON
            Route::post('ekstreler/{ekstre}/yapay-zeka', [EkstreAnalizController::class, 'baslat'])->middleware('throttle:ekstre')->name('ekstreler.yapay-zeka');
            Route::get('ekstreler/{ekstre}/yapay-zeka', [EkstreAnalizController::class, 'durum'])->name('ekstreler.yapay-zeka.durum');
            Route::post('ekstreler/{ekstre}/yapay-zeka/iptal', [EkstreAnalizController::class, 'iptal'])->name('ekstreler.yapay-zeka.iptal');
            Route::get('ekstreler/{ekstre}/yapay-zeka/json', [EkstreAnalizController::class, 'json'])->name('ekstreler.yapay-zeka.json');
            // Banka hesabı ekstresi: yalnızca yapay zekâ analizi ve önizleme
            Route::get('hesap-ekstreleri', [HesapEkstresiController::class, 'index'])->name('hesap-ekstreleri.index');
            Route::post('hesap-ekstreleri', [HesapEkstresiController::class, 'store'])->middleware('throttle:ekstre')->name('hesap-ekstreleri.store');
            Route::get('hesap-ekstreleri/{hesap}', [HesapEkstresiController::class, 'show'])->name('hesap-ekstreleri.show');
            Route::delete('hesap-ekstreleri/{hesap}', [HesapEkstresiController::class, 'destroy'])->name('hesap-ekstreleri.destroy');
            Route::post('ekstreler/{ekstre}/onayla', [EkstreController::class, 'onayla'])->name('ekstreler.onayla');

            Route::get('fatura-turleri', [SaglayiciController::class, 'turler'])->name('fatura-turleri.index');
            Route::post('fatura-turleri', [SaglayiciController::class, 'turEkle'])->name('fatura-turleri.store');
            Route::delete('fatura-turleri/{tur}', [SaglayiciController::class, 'turSil'])->name('fatura-turleri.destroy');
            Route::apiResource('saglayicilar', SaglayiciController::class)->except('show')->parameters(['saglayicilar' => 'saglayici']);
            Route::post('saglayicilar/{saglayici}/desenler', [SaglayiciController::class, 'desenEkle'])->name('saglayicilar.desenler.store');
            Route::delete('saglayici-desenleri/{desen}', [SaglayiciController::class, 'desenSil'])->name('saglayici-desenleri.destroy');
            Route::apiResource('faturalar', FaturaController::class)->except('show')->parameters(['faturalar' => 'fatura']);
            Route::post('faturalar/{fatura}/bagla', [FaturaController::class, 'bagla'])->name('faturalar.bagla');
            Route::post('faturalar/{fatura}/coz', [FaturaController::class, 'coz'])->name('faturalar.coz');
            Route::get('ozet', AileOzetController::class)->name('ozet');

            // Zekât: ayar, hesap (önizleme), yıl kayıtları (taslak → kesinleşmiş), elle borç, verilen zekât
            Route::get('zekat/ayar', [ZekatAyarController::class, 'show'])->name('zekat.ayar');
            Route::put('zekat/ayar', [ZekatAyarController::class, 'update'])->name('zekat.ayar.guncelle');
            Route::post('zekat/hesapla', [ZekatController::class, 'hesapla'])->name('zekat.hesapla');
            Route::get('zekat/yillar', [ZekatController::class, 'index'])->name('zekat.yillar.index');
            Route::post('zekat/yillar', [ZekatController::class, 'store'])->name('zekat.yillar.store');
            Route::get('zekat/yillar/{yil}', [ZekatController::class, 'show'])->name('zekat.yillar.show');
            Route::put('zekat/yillar/{yil}', [ZekatController::class, 'update'])->name('zekat.yillar.update');
            Route::delete('zekat/yillar/{yil}', [ZekatController::class, 'destroy'])->name('zekat.yillar.destroy');
            Route::post('zekat/yillar/{yil}/kesinlestir', [ZekatController::class, 'kesinlestir'])->name('zekat.yillar.kesinlestir');
            Route::get('zekat/borclar', [ZekatBorcController::class, 'index'])->name('zekat.borclar.index');
            Route::post('zekat/borclar', [ZekatBorcController::class, 'store'])->name('zekat.borclar.store');
            Route::delete('zekat/borclar/{borc}', [ZekatBorcController::class, 'destroy'])->name('zekat.borclar.destroy');
            Route::get('zekat/donemler', ZekatDonemController::class)->name('zekat.donemler');
            Route::get('zekat/odemeler', [ZekatOdemeController::class, 'index'])->name('zekat.odemeler.index');
            Route::post('zekat/odemeler', [ZekatOdemeController::class, 'store'])->name('zekat.odemeler.store');
            Route::put('zekat/odemeler/{odeme}', [ZekatOdemeController::class, 'update'])->name('zekat.odemeler.update');
            Route::delete('zekat/odemeler/{odeme}', [ZekatOdemeController::class, 'destroy'])->name('zekat.odemeler.destroy');
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
