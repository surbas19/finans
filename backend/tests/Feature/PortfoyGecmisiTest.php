<?php

namespace Tests\Feature;

use App\Enums\IslemTuru;
use App\Models\FiyatGecmisi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

class PortfoyGecmisiTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private function islem(Hesap $hesap, Varlik $varlik, IslemTuru $tur, float $miktar, ?float $fiyat, int $gunOnce): Islem
    {
        return Islem::factory()->create([
            'hesap_id' => $hesap->id, 'user_id' => $hesap->user_id, 'varlik_id' => $varlik->id, 'tur' => $tur,
            'miktar' => $miktar, 'birim_fiyat' => $fiyat, 'komisyon' => 0, 'tarih' => now()->subDays($gunOnce)->toDateString(),
        ]);
    }

    /**
     * @param  array<int, float>  $kapanislar  gün önce => kapanış
     */
    private function kapanislar(Varlik $varlik, array $kapanislar): void
    {
        foreach ($kapanislar as $gunOnce => $kapanis) {
            FiyatGecmisi::create(['varlik_id' => $varlik->id, 'tarih' => now()->subDays($gunOnce)->toDateString(), 'kapanis' => $kapanis]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function gecmis(User $user, int $gun = 30, ?int $hesapId = null): array
    {
        return collect(app(PortfoyHesabi::class)->gecmis($user, $hesapId, now()->subDays($gun), now()))->keyBy('tarih')->all();
    }

    private static function gun(int $gunOnce): string
    {
        return now()->subDays($gunOnce)->toDateString();
    }

    public function test_alis_satis_ve_kapanissiz_gun_onceki_kapanisla_dolar(): void
    {
        $hesap = Hesap::factory()->create();
        $hisse = $this->varlik('THYAO', fiyat: 130);
        $this->islem($hesap, $hisse, IslemTuru::Alis, 10, 100, 5);
        $this->islem($hesap, $hisse, IslemTuru::Satis, 4, 120, 2);
        $this->kapanislar($hisse, [6 => 90, 5 => 100, 4 => 110, 2 => 120, 1 => 125]); // 3 gün önce kapanış yok

        $g = $this->gecmis($hesap->user);

        // İlk işlemden başlar
        $this->assertSame([self::gun(5), self::gun(4), self::gun(3), self::gun(2), self::gun(1), self::gun(0)], array_keys($g));
        $this->assertEqualsWithDelta(1000, $g[self::gun(5)]['deger'], 0.01);
        $this->assertEqualsWithDelta(1000, $g[self::gun(5)]['maliyet'], 0.01);
        $this->assertEqualsWithDelta(0, $g[self::gun(5)]['kz'], 0.01);
        $this->assertEqualsWithDelta(1100, $g[self::gun(4)]['deger'], 0.01);
        $this->assertEqualsWithDelta(100, $g[self::gun(4)]['kz'], 0.01);
        $this->assertEqualsWithDelta(1100, $g[self::gun(3)]['deger'], 0.01); // ileri taşındı
        // Satış: 6 kaldı, maliyet 600
        $this->assertEqualsWithDelta(720, $g[self::gun(2)]['deger'], 0.01);
        $this->assertEqualsWithDelta(600, $g[self::gun(2)]['maliyet'], 0.01);
        $this->assertEqualsWithDelta(120, $g[self::gun(2)]['kz'], 0.01);
        $this->assertEqualsWithDelta(750, $g[self::gun(1)]['deger'], 0.01);
        // Bugün güncel fiyatla, özetteki toplamla aynı
        $this->assertEqualsWithDelta(780, $g[self::gun(0)]['deger'], 0.01);
        $this->assertEqualsWithDelta(180, $g[self::gun(0)]['kz'], 0.01);
        $this->assertEqualsWithDelta(app(PortfoyHesabi::class)->hesapla($hesap->user)['toplam']['deger'], $g[self::gun(0)]['deger'], 0.001);
        $this->assertFalse(collect($g)->contains('eksik', true));
    }

    public function test_usd_cevirisi_islem_fiyati_yedegi_ve_maliyeti_bilinmeyen(): void
    {
        $hesap = Hesap::factory()->create();
        $usd = $this->varlik('USD', 'doviz', 40);
        $this->kapanislar($usd, [3 => 30, 1 => 35]);
        $ons = $this->varlik('ONS', 'altin', 2000, 'USD');
        $this->kapanislar($ons, [2 => 2000, 1 => 2100]);
        $fon = $this->varlik('AFT', 'fon', null); // fiyatı ve geçmişi yok
        $ceyrek = $this->varlik('CEYREK', 'altin', 10000);
        $this->kapanislar($ceyrek, [2 => 9000]);
        $fiyatsiz = $this->varlik('XYZ', fiyat: null);

        $this->islem($hesap, $ons, IslemTuru::Alis, 1, 60000, 2);
        $this->islem($hesap, $fon, IslemTuru::Alis, 10, 5, 2);
        $this->islem($hesap, $ceyrek, IslemTuru::Mevcut, 2, null, 2);
        $this->islem($hesap, $fiyatsiz, IslemTuru::Mevcut, 3, null, 2);

        $g = $this->gecmis($hesap->user);

        // 2 gün önce: ons 2000 × 30 (USD 3 gün önceden taşındı) + fon 10 × 5 (işlem fiyatı) + çeyrek 2 × 9000; XYZ katılmaz
        $this->assertEqualsWithDelta(60000 + 50 + 18000, $g[self::gun(2)]['deger'], 0.01);
        $this->assertEqualsWithDelta(60050, $g[self::gun(2)]['maliyet'], 0.01); // çeyreğin maliyeti bilinmiyor
        $this->assertEqualsWithDelta(0, $g[self::gun(2)]['kz'], 0.01);
        $this->assertTrue($g[self::gun(2)]['eksik']);
        // Dün: ons 2100 × 35
        $this->assertEqualsWithDelta(73500 + 50 + 18000, $g[self::gun(1)]['deger'], 0.01);
        $this->assertEqualsWithDelta(13500, $g[self::gun(1)]['kz'], 0.01);
        // Bugün: güncel ons 2000 × 40, çeyrek 10000
        $this->assertEqualsWithDelta(80000 + 50 + 20000, $g[self::gun(0)]['deger'], 0.01);
        $this->assertEqualsWithDelta(20000, $g[self::gun(0)]['kz'], 0.01);
    }

    public function test_uzun_aralik_haftalik_orneklenir(): void
    {
        $hesap = Hesap::factory()->create();
        $hisse = $this->varlik('THYAO');
        $this->islem($hesap, $hisse, IslemTuru::Alis, 1, 100, 500);

        $g = app(PortfoyHesabi::class)->gecmis($hesap->user, null, now()->subDays(600), now());
        $son = array_pop($g);

        $this->assertSame(self::gun(0), $son['tarih']);
        $this->assertGreaterThan(60, count($g));
        $this->assertLessThan(80, count($g));
        foreach ($g as $nokta) {
            $this->assertTrue(Carbon::parse($nokta['tarih'])->isSunday(), $nokta['tarih']);
        }
    }

    public function test_uc_aralik_hesap_suzgeci_ve_kullanici_izolasyonu(): void
    {
        $sahip = User::factory()->create();
        $baska = User::factory()->create();
        $a = Hesap::factory()->create(['user_id' => $sahip->id]);
        $b = Hesap::factory()->create(['user_id' => $sahip->id]);
        $hisse = $this->varlik('THYAO');
        $this->islem($a, $hisse, IslemTuru::Alis, 1, 100, 10);
        $this->islem($b, $hisse, IslemTuru::Alis, 2, 100, 100);

        // Tümü ilk işlemden; hesap süzgeci yalnızca o hesabın işlemlerini alır
        $this->actingAs($sahip)->getJson('/api/v1/ozet/gecmis?aralik=T')->assertOk()
            ->assertJsonPath('gecmis.0.tarih', self::gun(100))
            ->assertJsonPath('gecmis.0.deger', 200);
        $this->actingAs($sahip)->getJson("/api/v1/ozet/gecmis?aralik=T&hesap_id={$a->id}")->assertOk()
            ->assertJsonPath('gecmis.0.tarih', self::gun(10))
            ->assertJsonCount(11, 'gecmis');
        // 1A: 31 gün önceden
        $this->actingAs($sahip)->getJson('/api/v1/ozet/gecmis?aralik=1A')->assertOk()
            ->assertJsonPath('gecmis.0.tarih', self::gun(31))
            ->assertJsonCount(32, 'gecmis');
        $this->actingAs($sahip)->getJson('/api/v1/ozet/gecmis?aralik=5Y')->assertJsonValidationErrors('aralik');

        // Başka kullanıcı: hesabı seçemez, kendi serisi boş
        $this->actingAs($baska)->getJson("/api/v1/ozet/gecmis?hesap_id={$a->id}")->assertJsonValidationErrors('hesap_id');
        $this->actingAs($baska)->getJson('/api/v1/ozet/gecmis?aralik=T')->assertOk()->assertJsonCount(0, 'gecmis');
    }

    public function test_giris_yapmayan_erisemez(): void
    {
        $this->getJson('/api/v1/ozet/gecmis')->assertUnauthorized();
    }
}
