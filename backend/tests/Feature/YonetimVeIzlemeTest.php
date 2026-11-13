<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

class YonetimVeIzlemeTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    public function test_kullaniciyi_yalnizca_yonetici_ekler_ve_kapatir(): void
    {
        $yonetici = User::factory()->yonetici()->create();
        $kisi = User::factory()->create();

        $this->actingAs($kisi)->getJson('/api/v1/yonetim/kullanicilar')->assertForbidden();
        $this->actingAs($kisi)->postJson('/api/v1/yonetim/kullanicilar', [])->assertForbidden();
        $this->actingAs($kisi)->putJson("/api/v1/yonetim/kullanicilar/{$yonetici->id}", ['aktif' => false])->assertForbidden();

        $this->actingAs($yonetici)->postJson('/api/v1/yonetim/kullanicilar', ['ad' => 'Ay', 'soyad' => 'Şe', 'email' => 'yeni@ornek.test', 'sifre' => 'kisa'])
            ->assertJsonValidationErrors('sifre');
        $this->actingAs($yonetici)->postJson('/api/v1/yonetim/kullanicilar', ['ad' => 'Ay', 'soyad' => 'Şe', 'email' => 'Yeni@Ornek.test', 'sifre' => 'GucluSifre1'])
            ->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'yeni@ornek.test', 'yonetici' => false]);

        $this->actingAs($yonetici)->putJson("/api/v1/yonetim/kullanicilar/{$kisi->id}", ['aktif' => false])->assertOk();
        $this->assertFalse($kisi->fresh()->aktif);
        // Kendini kapatamaz / yöneticiliğini kaldıramaz
        $this->actingAs($yonetici)->putJson("/api/v1/yonetim/kullanicilar/{$yonetici->id}", ['yonetici' => false])->assertJsonValidationErrors('aktif');
    }

    public function test_kaynak_durumu_ve_elle_guncelleme_yalnizca_yonetici(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/yonetim/kaynaklar')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/yonetim/kaynaklar/guncelle')->assertForbidden();
        $this->actingAs(User::factory()->yonetici()->create())->getJson('/api/v1/yonetim/kaynaklar')->assertOk()
            ->assertJsonPath('kaynaklar.0.kaynak', 'truncgil');
    }

    public function test_izleme_listesi_kisiye_ozel(): void
    {
        $ben = User::factory()->create();
        $baska = User::factory()->create();
        $usd = $this->varlik('USD', 'doviz', 50);
        $eur = $this->varlik('EUR', 'doviz', 55);

        $this->actingAs($ben)->postJson('/api/v1/izleme', ['varlik_id' => $usd->id])->assertCreated();
        $this->actingAs($ben)->postJson('/api/v1/izleme', ['varlik_id' => $eur->id])->assertCreated();
        $this->actingAs($ben)->postJson('/api/v1/izleme', ['varlik_id' => $usd->id])->assertCreated(); // tekrar: kopya yok
        $this->actingAs($ben)->putJson('/api/v1/izleme/sira', ['varlik_idleri' => [$eur->id, $usd->id]])->assertOk();
        $this->actingAs($ben)->getJson('/api/v1/izleme')->assertJsonCount(2, 'varliklar')->assertJsonPath('varliklar.0.kod', 'EUR');
        $this->actingAs($ben)->getJson('/api/v1/varliklar')->assertJsonPath('varliklar.0.izleniyor', true);

        $this->actingAs($baska)->getJson('/api/v1/izleme')->assertJsonCount(0, 'varliklar');
        $this->actingAs($baska)->deleteJson("/api/v1/izleme/{$usd->id}")->assertOk();
        $this->actingAs($ben)->getJson('/api/v1/izleme')->assertJsonCount(2, 'varliklar');

        $this->actingAs($ben)->deleteJson("/api/v1/izleme/{$usd->id}")->assertOk();
        $this->actingAs($ben)->getJson('/api/v1/izleme')->assertJsonCount(1, 'varliklar');
    }
}
