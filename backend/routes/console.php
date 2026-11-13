<?php

use App\Fiyat\FiyatGuncelleyici;
use App\Fiyat\Katalog;
use App\Models\User;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Zamanlanmış komut AYNI PHP sürecinde çalışır (Artisan::call). Zamanlayıcının command() yöntemi her komut için ayrı
 * süreç açar (proc_open); paylaşımlı barındırmada proc_open kapalı olduğu için komutlar hiç çalışmaz. Yeni zamanlanmış
 * komut bununla eklenir (okul sistemindeki kalıp).
 */
$zamanla = function (string $komut, string $ad): CallbackEvent {
    return Schedule::call(function () use ($komut): void {
        $cikti = new BufferedOutput;
        Artisan::call($komut, [], $cikti);
        $metin = trim($cikti->fetch());
        if ($metin !== '') {
            echo $metin.PHP_EOL;
        }
    })->name($ad);
};

Artisan::command('fiyat:guncelle {kaynak?}', function (FiyatGuncelleyici $guncelleyici): void {
    foreach ($guncelleyici->guncelle($this->argument('kaynak')) as $kaynak => $sonuc) {
        $this->line(is_int($sonuc) ? "{$kaynak}: {$sonuc} fiyat" : "{$kaynak}: {$sonuc}");
    }
    $sayi = $guncelleyici->gecmisleriCek();
    if ($sayi > 0) {
        $this->line("{$sayi} varlığın geçmiş fiyatları çekildi.");
    }
})->purpose('Fiyatları kaynaklardan günceller, yeni varlıkların geçmişini çeker');

// 15 dakikada bir. Kilit 10 dakikada düşer (paylaşımlı sunucu bir çalışmayı yarıda keserse ertesi çalışma takılmasın).
$zamanla('fiyat:guncelle', 'fiyat-guncelle')->everyFifteenMinutes()->withoutOverlapping(10);

Artisan::command('katalog:esitle', function (): void {
    $this->info(Katalog::esitle().' hazır varlık eşitlendi.');
})->purpose('Hazır varlık kataloğunu (altın, döviz, ons, ana kriptolar) ekler / günceller');

/**
 * Canlıda ilk kurulum: hiç kullanıcı yoksa .env'deki KURULUM_YONETICI="e-posta|Ad|Soyad" ile yönetici açar,
 * geçici şifreyi çıktıya (cron → deploy/kurulum.log) yazar. Kullanıcı varsa hiçbir şey yapmaz.
 */
Artisan::command('kurulum:yonetici', function (): int {
    if (User::exists()) {
        return 0;
    }
    $parcalar = array_map('trim', explode('|', (string) env('KURULUM_YONETICI', '')));
    if (count($parcalar) !== 3 || ! filter_var($parcalar[0], FILTER_VALIDATE_EMAIL)) {
        $this->error('KURULUM_YONETICI .env ayarı "e-posta|Ad|Soyad" biçiminde olmalı.');

        return 1;
    }
    $sifre = Str::password(14, symbols: false);
    User::create([
        'email' => mb_strtolower($parcalar[0]),
        'ad' => $parcalar[1],
        'soyad' => $parcalar[2],
        'password' => $sifre,
        'yonetici' => true,
        'aktif' => true,
    ]);
    $this->info("Yönetici açıldı: {$parcalar[0]} — geçici şifre: {$sifre} (girişten sonra Profil'den değiştirin, bu log dosyasını silin)");

    return 0;
})->purpose('Hiç kullanıcı yoksa ilk yöneticiyi açar');
