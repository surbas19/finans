<?php

namespace Tests\Feature;

use App\Models\Fiyat;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoVeZamanlayiciTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_verisi_kurulur_ve_ozet_dolu(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ben = User::where('email', 'ben@finans.test')->firstOrFail();

        $ozet = $this->actingAs($ben)->getJson('/api/v1/ozet')->assertOk();
        $this->assertCount(5, $ozet->json('hesaplar'));
        $this->assertGreaterThan(0, $ozet->json('toplam.deger'));
        $this->assertSame(0, $ozet->json('toplam.fiyatsiz'));
        // Nakit ve bireysel emeklilik dağılımda; fiyat satırları yok (birimi 1 TL)
        $turler = array_column($ozet->json('dagilim'), 'tur');
        $this->assertContains('nakit', $turler);
        $this->assertContains('bes', $turler);
        $this->assertSame(0, Fiyat::whereHas('varlik', fn ($q) => $q->where('kaynak', 'sabit'))->count());
        $this->assertTrue($ozet->json('toplam.maliyeti_eksik'));
        // İkinci demo kullanıcısının hiçbir şeyi yok
        $this->actingAs(User::where('email', 'es@finans.test')->first())->getJson('/api/v1/ozet')->assertJsonCount(0, 'hesaplar');
    }

    public function test_zamanlanmis_komutlar_ayri_surec_acmaz(): void
    {
        $kaynak = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('Schedule::command(', $kaynak, 'Canlıda proc_open kapalı: $zamanla() kullanın.');
        $this->assertStringContainsString("\$zamanla('fiyat:guncelle'", $kaynak);
    }

    public function test_kurulum_yoneticisi_yalnizca_kullanici_yokken_acilir(): void
    {
        putenv('KURULUM_YONETICI=ilk@ornek.test|İlk|Yönetici');
        $this->assertSame(0, Artisan::call('kurulum:yonetici'));
        $this->assertTrue(User::where('email', 'ilk@ornek.test')->where('yonetici', true)->exists());
        $this->assertStringContainsString('geçici şifre', Artisan::output());

        putenv('KURULUM_YONETICI=ikinci@ornek.test|İki|Yönetici');
        Artisan::call('kurulum:yonetici');
        $this->assertSame(1, User::count());

        // Şifre sıfırlama yalnızca KURULUM_YONETICI e-postasındaki yöneticiye
        $this->assertSame(1, Artisan::call('kurulum:yonetici', ['--sifirla' => true]));
        putenv('KURULUM_YONETICI=ilk@ornek.test|İlk|Yönetici');
        $eski = User::first()->password;
        $this->assertSame(0, Artisan::call('kurulum:yonetici', ['--sifirla' => true]));
        $this->assertNotSame($eski, User::first()->fresh()->password);
        putenv('KURULUM_YONETICI');
    }
}
