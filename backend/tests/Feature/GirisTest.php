<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionProperty;
use Tests\TestCase;

class GirisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Arayüzden gelen istek (Sanctum oturum açar)
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_giris_cikis_ve_ben(): void
    {
        User::factory()->create(['email' => 'ben@ornek.test']);

        $this->postJson('/api/v1/giris', ['email' => 'ben@ornek.test', 'sifre' => 'yanlis'])->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/giris', ['email' => 'BEN@ornek.test', 'sifre' => 'password'])->assertOk()
            ->assertJsonPath('kullanici.email', 'ben@ornek.test')
            ->assertJsonMissingPath('kullanici.password');
        $this->getJson('/api/v1/ben')->assertOk();
        $this->assertNotNull(User::first()->son_giris);
    }

    public function test_kapali_kullanici_giremez(): void
    {
        User::factory()->create(['email' => 'kapali@ornek.test', 'aktif' => false]);
        $this->postJson('/api/v1/giris', ['email' => 'kapali@ornek.test', 'sifre' => 'password'])->assertJsonValidationErrors('email');
    }

    public function test_sifre_degistirme_kurala_uyar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/v1/ben/sifre', ['mevcut_sifre' => 'yanlis', 'yeni_sifre' => 'YeniSifre123', 'yeni_sifre_confirmation' => 'YeniSifre123'])
            ->assertJsonValidationErrors('mevcut_sifre');
        $this->actingAs($user)->putJson('/api/v1/ben/sifre', ['mevcut_sifre' => 'password', 'yeni_sifre' => 'kisa', 'yeni_sifre_confirmation' => 'kisa'])
            ->assertJsonValidationErrors('yeni_sifre');
        $this->actingAs($user)->putJson('/api/v1/ben/sifre', ['mevcut_sifre' => 'password', 'yeni_sifre' => 'YeniSifre123', 'yeni_sifre_confirmation' => 'YeniSifre123'])
            ->assertOk();
        $this->assertTrue(Hash::check('YeniSifre123', $user->fresh()->password));
    }

    public function test_csrf_yalnizca_koken_denetimi_xsrf_cerezi_yok(): void
    {
        $ozellik = new ReflectionProperty(PreventRequestForgery::class, 'originOnly');
        $this->assertTrue($ozellik->getValue(), 'bootstrap/app.php preventRequestForgery(originOnly: true) olmalı (okulun XSRF çereziyle çakışmasın)');
        $this->assertSame('finans_oturum', config('session.cookie'));
        $this->assertNull(config('session.domain'));
    }

    public function test_arayuz_yolu_spa_ya_duser_api_dusmez(): void
    {
        $this->get('/hesaplar/3')->assertStatus(is_file(resource_path('spa/index.html')) ? 200 : 503);
        $this->getJson('/api/v1/yok')->assertNotFound();
    }
}
