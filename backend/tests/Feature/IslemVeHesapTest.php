<?php

namespace Tests\Feature;

use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

class IslemVeHesapTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    public function test_hesap_ekleme_duzenleme_silme_ve_ad_benzersizligi(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/hesaplar', ['ad' => 'Banka kasası', 'tur' => 'kasa'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/hesaplar', ['ad' => 'Banka kasası', 'tur' => 'kasa'])->assertJsonValidationErrors('ad');
        // Başka kullanıcı aynı adı kullanabilir
        $this->actingAs(User::factory()->create())->postJson('/api/v1/hesaplar', ['ad' => 'Banka kasası', 'tur' => 'kasa'])->assertCreated();

        $hesap = Hesap::where('user_id', $user->id)->first();
        $this->actingAs($user)->putJson("/api/v1/hesaplar/{$hesap->id}", ['ad' => 'Kasa', 'tur' => 'kasa'])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/hesaplar')->assertOk()->assertJsonPath('hesaplar.0.ad', 'Kasa');
        $this->actingAs($user)->deleteJson("/api/v1/hesaplar/{$hesap->id}")->assertOk();
        $this->assertDatabaseMissing('hesaplar', ['id' => $hesap->id]);
    }

    public function test_baska_kullanicinin_hesabi_ve_islemi_gorunmez_degismez(): void
    {
        $sahip = User::factory()->create();
        $baska = User::factory()->create();
        $hesap = Hesap::factory()->create(['user_id' => $sahip->id]);
        $hisse = $this->varlik('THYAO');
        $islem = Islem::factory()->create(['hesap_id' => $hesap->id, 'user_id' => $sahip->id, 'varlik_id' => $hisse->id]);

        $this->actingAs($baska)->getJson("/api/v1/hesaplar/{$hesap->id}")->assertForbidden();
        $this->actingAs($baska)->putJson("/api/v1/hesaplar/{$hesap->id}", ['ad' => 'X', 'tur' => 'diger'])->assertForbidden();
        $this->actingAs($baska)->deleteJson("/api/v1/hesaplar/{$hesap->id}")->assertForbidden();
        $this->actingAs($baska)->putJson("/api/v1/islemler/{$islem->id}", ['hesap_id' => $hesap->id, 'varlik_id' => $hisse->id, 'tur' => 'alis', 'miktar' => 1, 'birim_fiyat' => 1, 'tarih' => now()->toDateString()])->assertForbidden();
        $this->actingAs($baska)->deleteJson("/api/v1/islemler/{$islem->id}")->assertForbidden();
        // Başkasının hesabına işlem girilemez
        $this->actingAs($baska)->postJson('/api/v1/islemler', ['hesap_id' => $hesap->id, 'varlik_id' => $hisse->id, 'tur' => 'alis', 'miktar' => 1, 'birim_fiyat' => 1, 'tarih' => now()->toDateString()])
            ->assertJsonValidationErrors('hesap_id');
        // Listeler ve özet boş
        $this->actingAs($baska)->getJson('/api/v1/islemler')->assertOk()->assertJsonCount(0, 'islemler');
        $this->actingAs($baska)->getJson('/api/v1/islemler?hesap_id='.$hesap->id)->assertOk()->assertJsonCount(0, 'islemler');
        $this->actingAs($baska)->getJson('/api/v1/hesaplar')->assertOk()->assertJsonCount(0, 'hesaplar');
        $this->actingAs($baska)->getJson('/api/v1/ozet')->assertOk()->assertJsonPath('toplam.deger', 0)->assertJsonCount(0, 'pozisyonlar');
        $this->actingAs($baska)->getJson('/api/v1/ozet?hesap_id='.$hesap->id)->assertJsonValidationErrors('hesap_id');
        $this->actingAs($baska)->getJson("/api/v1/varliklar/{$hisse->id}")->assertOk()->assertJsonCount(0, 'pozisyonlar');
        $this->assertDatabaseHas('islemler', ['id' => $islem->id, 'miktar' => 10]);
    }

    public function test_islem_ekleme_satis_denetimi_duzeltme_ve_silme(): void
    {
        $user = User::factory()->create();
        $hesap = Hesap::factory()->create(['user_id' => $user->id]);
        $hisse = $this->varlik('ASELS', fiyat: 150);
        $temel = ['hesap_id' => $hesap->id, 'varlik_id' => $hisse->id];

        $alis = $this->actingAs($user)->postJson('/api/v1/islemler', [...$temel, 'tur' => 'alis', 'miktar' => 10, 'birim_fiyat' => 100, 'komisyon' => 5, 'tarih' => now()->subDays(10)->toDateString()])
            ->assertCreated()->json('islem.id');
        // Alışta fiyat zorunlu; "elde olan"da değil
        $this->actingAs($user)->postJson('/api/v1/islemler', [...$temel, 'tur' => 'alis', 'miktar' => 1, 'tarih' => now()->toDateString()])->assertJsonValidationErrors('birim_fiyat');
        // İleri tarih yok
        $this->actingAs($user)->postJson('/api/v1/islemler', [...$temel, 'tur' => 'alis', 'miktar' => 1, 'birim_fiyat' => 1, 'tarih' => now()->addDay()->toDateString()])->assertJsonValidationErrors('tarih');
        // Eldekinden fazla satış ve alıştan önceki tarihte satış
        $this->actingAs($user)->postJson('/api/v1/islemler', [...$temel, 'tur' => 'satis', 'miktar' => 11, 'birim_fiyat' => 120, 'tarih' => now()->toDateString()])->assertJsonValidationErrors('miktar');
        $this->actingAs($user)->postJson('/api/v1/islemler', [...$temel, 'tur' => 'satis', 'miktar' => 1, 'birim_fiyat' => 120, 'tarih' => now()->subDays(20)->toDateString()])->assertJsonValidationErrors('miktar');
        $satis = $this->actingAs($user)->postJson('/api/v1/islemler', [...$temel, 'tur' => 'satis', 'miktar' => 8, 'birim_fiyat' => 120, 'tarih' => now()->toDateString()])
            ->assertCreated()->json('islem.id');

        // Alışı satıştan küçük yapmak ve silmek reddedilir
        $this->actingAs($user)->putJson("/api/v1/islemler/{$alis}", [...$temel, 'tur' => 'alis', 'miktar' => 5, 'birim_fiyat' => 100, 'tarih' => now()->subDays(10)->toDateString()])
            ->assertJsonValidationErrors('miktar');
        $this->actingAs($user)->deleteJson("/api/v1/islemler/{$alis}")->assertJsonValidationErrors('islem');
        // Önce satış silinir, sonra alış
        $this->actingAs($user)->deleteJson("/api/v1/islemler/{$satis}")->assertOk();
        $this->actingAs($user)->deleteJson("/api/v1/islemler/{$alis}")->assertOk();
        $this->assertDatabaseCount('islemler', 0);
    }

    public function test_islemi_baska_hesaba_tasimak_eski_hesabi_da_denetler(): void
    {
        $user = User::factory()->create();
        $a = Hesap::factory()->create(['user_id' => $user->id]);
        $b = Hesap::factory()->create(['user_id' => $user->id]);
        $hisse = $this->varlik('BIMAS');
        $alis = Islem::factory()->create(['hesap_id' => $a->id, 'user_id' => $user->id, 'varlik_id' => $hisse->id, 'miktar' => 10, 'tarih' => now()->subDays(5)]);
        Islem::factory()->create(['hesap_id' => $a->id, 'user_id' => $user->id, 'varlik_id' => $hisse->id, 'tur' => 'satis', 'miktar' => 4, 'tarih' => now()->subDays(2)]);

        $this->actingAs($user)->putJson("/api/v1/islemler/{$alis->id}", ['hesap_id' => $b->id, 'varlik_id' => $hisse->id, 'tur' => 'alis', 'miktar' => 10, 'birim_fiyat' => 100, 'tarih' => now()->subDays(5)->toDateString()])
            ->assertJsonValidationErrors('miktar');
        $this->assertDatabaseHas('islemler', ['id' => $alis->id, 'hesap_id' => $a->id]);
    }

    public function test_giris_yapmayan_erisemez(): void
    {
        $this->getJson('/api/v1/ozet')->assertUnauthorized();
        $this->getJson('/api/v1/hesaplar')->assertUnauthorized();
    }
}
