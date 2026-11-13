<?php

namespace Database\Seeders;

use App\Enums\HesapTuru;
use App\Enums\IslemTuru;
use App\Models\Fiyat;
use App\Models\FiyatGecmisi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\IzlemeKaydi;
use App\Models\User;
use App\Models\Varlik;
use Illuminate\Database\Seeder;

/**
 * Yalnızca yerel ortam: iki kullanıcı, dört hesap, örnek işlemler, sabit tohumlu örnek fiyat ve 1 yıllık geçmiş.
 * Kaynaklara istek atılmaz (gecmis_tarihi dolu); gerçek fiyatlar `php artisan fiyat:guncelle` ile gelir.
 * Her bilgisayarda aynı veri oluşsun diye rand() değil fake() kullanılır.
 */
class DemoSeeder extends Seeder
{
    /** kod => [fiyat, alış, satış] (TL; ONS USD) */
    private const FIYATLAR = [
        'GRAM' => [6300, 6300, 6310], 'HAS' => [6278, 6278, 6279], 'CEYREK' => [10275, 10275, 10377],
        'YARIM' => [20550, 20550, 20754], 'TAM' => [41100, 41100, 41400], 'CUMHURIYET' => [42127, 42127, 42449],
        'ATA' => [43000, 43000, 43350], 'AYAR22' => [5741, 5741, 5947], 'AYAR18' => [4700, 4700, 4800],
        'AYAR14' => [3650, 3650, 3750], 'GUMUS' => [80.42, 80.42, 80.51], 'ONS' => [3917.5, null, null],
        'USD' => [50.36, 50.36, 50.37], 'EUR' => [55.68, 55.68, 55.77], 'GBP' => [66.66, 66.66, 66.67],
        'CHF' => [60.25, 60.25, 60.29], 'SAR' => [13.41, 13.41, 13.43], 'AED' => [13.71, 13.71, 13.72],
        'BTC' => [3447255, 3445531, 3445532], 'ETH' => [121500, 121480, 121520], 'USDT' => [50.41, 50.40, 50.42],
        'THYAO' => [319, null, null], 'ASELS' => [182.4, null, null], 'BIMAS' => [612.5, null, null], 'AFT' => [1.2534, null, null],
    ];

    public function run(): void
    {
        fake()->seed(2026);

        $ben = User::factory()->yonetici()->create(['ad' => 'Demo', 'soyad' => 'Yönetici', 'email' => 'ben@finans.test']);
        User::factory()->create(['ad' => 'Demo', 'soyad' => 'Kullanıcı', 'email' => 'es@finans.test']);

        $ek = [
            ['tur' => 'hisse', 'kod' => 'THYAO', 'ad' => 'Türk Hava Yolları A.O.', 'birim' => 'pay', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'THYAO.IS', 'gecikmeli' => true],
            ['tur' => 'hisse', 'kod' => 'ASELS', 'ad' => 'Aselsan Elektronik Sanayi ve Ticaret A.Ş.', 'birim' => 'pay', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'ASELS.IS', 'gecikmeli' => true],
            ['tur' => 'hisse', 'kod' => 'BIMAS', 'ad' => 'BİM Birleşik Mağazalar A.Ş.', 'birim' => 'pay', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'BIMAS.IS', 'gecikmeli' => true],
            ['tur' => 'fon', 'kod' => 'AFT', 'ad' => 'Örnek teknoloji fonu', 'birim' => 'pay', 'kaynak' => 'elle', 'kaynak_kodu' => null],
        ];
        foreach ($ek as $kalem) {
            Varlik::create([...$kalem, 'aktif' => true, 'sira' => 1000, 'ekleyen_id' => $ben->id]);
        }

        $this->fiyatlar();

        $v = Varlik::pluck('id', 'kod');
        $hesap = fn (string $ad, HesapTuru $tur, string $renk, int $sira): Hesap => Hesap::create(['user_id' => $ben->id, 'ad' => $ad, 'tur' => $tur, 'renk' => $renk, 'sira' => $sira]);
        $yatirim = $hesap('Yatırım hesabım', HesapTuru::AraciKurum, 'teal', 1);
        $es = $hesap('Eşimin hesabı', HesapTuru::AraciKurum, 'violet', 2);
        $kasa = $hesap('Banka kasası', HesapTuru::Kasa, 'amber', 3);
        $ev = $hesap('Evde', HesapTuru::Ev, 'rose', 4);
        $bes = $hesap('Bireysel emeklilik', HesapTuru::Bes, 'indigo', 5);

        $islem = function (Hesap $h, string $kod, IslemTuru $tur, float $miktar, ?float $fiyat, int $gunOnce, float $komisyon = 0) use ($ben, $v): void {
            Islem::create([
                'user_id' => $ben->id, 'hesap_id' => $h->id, 'varlik_id' => $v[$kod], 'tur' => $tur,
                'miktar' => $miktar, 'birim_fiyat' => $fiyat, 'komisyon' => $komisyon, 'tarih' => now()->subDays($gunOnce)->toDateString(),
            ]);
        };
        $islem($yatirim, 'THYAO', IslemTuru::Alis, 100, 280, 180, 28);
        $islem($yatirim, 'THYAO', IslemTuru::Alis, 50, 300, 90, 15);
        $islem($yatirim, 'THYAO', IslemTuru::Satis, 30, 320, 30, 10);
        $islem($yatirim, 'ASELS', IslemTuru::Alis, 200, 140, 120, 28);
        $islem($yatirim, 'BTC', IslemTuru::Alis, 0.01, 2800000, 200);
        $islem($yatirim, 'USD', IslemTuru::Alis, 1000, 41.2, 240);
        $islem($es, 'BIMAS', IslemTuru::Alis, 40, 520, 150, 20);
        $islem($es, 'AFT', IslemTuru::Alis, 10000, 0.85, 300);
        $islem($kasa, 'CEYREK', IslemTuru::Mevcut, 10, null, 365);
        $islem($kasa, 'GRAM', IslemTuru::Alis, 50, 3800, 330);
        $islem($ev, 'AYAR22', IslemTuru::Mevcut, 60, null, 365);
        $islem($ev, 'USD', IslemTuru::Mevcut, 500, 35, 365);
        // Nakit: birim 1 TL (yatırma = alış, çekme = satış)
        $islem($yatirim, 'TL', IslemTuru::Mevcut, 15000, 1, 240);
        $islem($yatirim, 'TL', IslemTuru::Alis, 20000, 1, 60);
        $islem($yatirim, 'TL', IslemTuru::Satis, 5000, 1, 20);
        // Bireysel emeklilik: toplam tutar ve kâr yüzdesi (birim fiyat = 1 / (1 + kâr))
        $islem($bes, 'BES', IslemTuru::Deger, 120000, round(1 / 1.18, 8), 300);
        $islem($bes, 'BES', IslemTuru::Deger, 150000, round(1 / 1.22, 8), 150);
        $islem($bes, 'BES', IslemTuru::Deger, 185000, round(1 / 1.27, 8), 10);

        foreach (['GRAM', 'CEYREK', 'USD', 'EUR', 'ONS', 'THYAO', 'BTC'] as $sira => $kod) {
            IzlemeKaydi::create(['user_id' => $ben->id, 'varlik_id' => $v[$kod], 'sira' => $sira + 1]);
        }

        // Aile finansı: iki kullanıcı aynı hanede; gelirler, kartlar, ekstre, faturalar
        $this->call(AileDemoSeeder::class);
    }

    /**
     * Güncel fiyat ve geriye doğru rastgele yürüyüşle 1 yıllık günlük kapanış.
     */
    private function fiyatlar(): void
    {
        $oynaklik = ['altin' => 0.008, 'doviz' => 0.004, 'hisse' => 0.02, 'kripto' => 0.035, 'fon' => 0.012];
        // Nakit ve bireysel emekliliğin fiyatı yok (birimi 1 TL)
        foreach (Varlik::where('kaynak', '!=', 'sabit')->get() as $varlik) {
            [$fiyat, $alis, $satis] = self::FIYATLAR[$varlik->kod] ?? [100, null, null];
            $gunluk = fake()->randomFloat(4, -1.5, 1.5);
            Fiyat::create([
                'varlik_id' => $varlik->id, 'fiyat' => $fiyat, 'alis' => $alis, 'satis' => $satis,
                'onceki_kapanis' => round($fiyat / (1 + $gunluk / 100), 8), 'degisim_yuzde' => $gunluk,
                'kaynak' => $varlik->kaynak, 'elle' => $varlik->kaynak === 'elle', 'zaman' => now(),
            ]);

            $satirlar = [];
            $deger = $fiyat;
            $s = $oynaklik[$varlik->tur->value] ?? 0.01;
            for ($gun = 0; $gun <= 365; $gun++) {
                $satirlar[] = ['varlik_id' => $varlik->id, 'tarih' => now()->subDays($gun)->toDateString(), 'kapanis' => round($deger, 8)];
                // Geçmişe doğru: hafif yükseliş eğilimi (bugünden geriye giderken düşer)
                $deger = $deger / (1 + 0.0012 + fake()->randomFloat(5, -$s, $s));
            }
            foreach (array_chunk($satirlar, 200) as $parca) {
                FiyatGecmisi::insert($parca);
            }
            $varlik->forceFill(['gecmis_tarihi' => now()->toDateString()])->save();
        }
    }
}
