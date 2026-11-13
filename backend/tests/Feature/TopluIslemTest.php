<?php

namespace Tests\Feature;

use App\Models\Hesap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

class TopluIslemTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    public function test_elde_olan_varliklar_toplu_eklenir(): void
    {
        $user = User::factory()->create();
        $hesap = Hesap::factory()->create(['user_id' => $user->id]);
        $gram = $this->varlik('GRAM', 'altin', fiyat: 3000);
        $usd = $this->varlik('USD', 'doviz', fiyat: 34);
        $tarih = now()->subMonths(3)->toDateString();

        $this->actingAs($user)->postJson('/api/v1/islemler/toplu', [
            'hesap_id' => $hesap->id,
            'satirlar' => [
                ['varlik_id' => $gram->id, 'miktar' => 25, 'tarih' => now()->toDateString()],
                ['varlik_id' => $usd->id, 'miktar' => 1500, 'birim_fiyat' => 32.4, 'tarih' => $tarih, 'not' => 'Kasadaki dolar'],
                ['varlik_id' => $gram->id, 'miktar' => 2.5, 'birim_fiyat' => 2500, 'tarih' => $tarih],
            ],
        ])->assertCreated()->assertJsonPath('eklenen', 3);

        $this->assertDatabaseCount('islemler', 3);
        $this->assertDatabaseHas('islemler', ['user_id' => $user->id, 'hesap_id' => $hesap->id, 'varlik_id' => $gram->id, 'tur' => 'mevcut', 'miktar' => 25, 'birim_fiyat' => null, 'komisyon' => 0]);
        $this->assertDatabaseHas('islemler', ['varlik_id' => $usd->id, 'tur' => 'mevcut', 'birim_fiyat' => 32.4, 'not' => 'Kasadaki dolar']);
        $this->actingAs($user)->getJson("/api/v1/hesaplar/{$hesap->id}")->assertOk()->assertJsonCount(2, 'pozisyonlar');
    }

    public function test_baska_kullanicinin_hesabina_toplu_eklenemez(): void
    {
        $sahip = User::factory()->create();
        $hesap = Hesap::factory()->create(['user_id' => $sahip->id]);
        $gram = $this->varlik('GRAM', 'altin');

        $this->actingAs(User::factory()->create())->postJson('/api/v1/islemler/toplu', [
            'hesap_id' => $hesap->id,
            'satirlar' => [['varlik_id' => $gram->id, 'miktar' => 10, 'tarih' => now()->toDateString()]],
        ])->assertJsonValidationErrors('hesap_id');

        $this->assertDatabaseCount('islemler', 0);
    }

    public function test_bir_satir_hataliysa_hicbiri_eklenmez(): void
    {
        $user = User::factory()->create();
        $hesap = Hesap::factory()->create(['user_id' => $user->id]);
        $gram = $this->varlik('GRAM', 'altin');

        $this->actingAs($user)->postJson('/api/v1/islemler/toplu', [
            'hesap_id' => $hesap->id,
            'satirlar' => [
                ['varlik_id' => $gram->id, 'miktar' => 10, 'tarih' => now()->toDateString()],
                ['varlik_id' => $gram->id, 'miktar' => 0, 'tarih' => now()->toDateString()],
                ['varlik_id' => $gram->id, 'miktar' => 1, 'tarih' => now()->addDay()->toDateString()],
                ['varlik_id' => 999999, 'miktar' => 1, 'tarih' => now()->toDateString()],
            ],
        ])->assertJsonValidationErrors(['satirlar.1.miktar', 'satirlar.2.tarih', 'satirlar.3.varlik_id'])
            ->assertJsonMissingValidationErrors('satirlar.0.miktar');

        $this->assertDatabaseCount('islemler', 0);
    }

    public function test_satir_siniri_bos_liste_ve_giris(): void
    {
        $user = User::factory()->create();
        $hesap = Hesap::factory()->create(['user_id' => $user->id]);
        $gram = $this->varlik('GRAM', 'altin');
        $satir = ['varlik_id' => $gram->id, 'miktar' => 1, 'tarih' => now()->toDateString()];

        $this->actingAs($user)->postJson('/api/v1/islemler/toplu', ['hesap_id' => $hesap->id, 'satirlar' => array_fill(0, 201, $satir)])
            ->assertJsonValidationErrors('satirlar');
        $this->actingAs($user)->postJson('/api/v1/islemler/toplu', ['hesap_id' => $hesap->id, 'satirlar' => []])
            ->assertJsonValidationErrors('satirlar');
        $this->assertDatabaseCount('islemler', 0);

        auth()->forgetGuards();
        $this->postJson('/api/v1/islemler/toplu', ['hesap_id' => $hesap->id, 'satirlar' => [$satir]])->assertUnauthorized();
    }
}
