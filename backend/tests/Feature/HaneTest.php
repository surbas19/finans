<?php

namespace Tests\Feature;

use App\Models\Hane;
use App\Models\HaneUyesi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Hane: aynı hanedeki kullanıcılar hesapları ve işlemleri birlikte görür / değiştirir; başka hane hiçbir şey görmez.
 */
class HaneTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    /** İlk kullanıcının hanesi kurulur, diğerleri ona taşınır */
    private function ayniHane(User ...$kullanicilar): Hane
    {
        $haneler = app(HaneYonetimi::class);
        $hane = $haneler->hazirla($kullanicilar[0]);
        foreach (array_slice($kullanicilar, 1) as $u) {
            $haneler->tasi($u, $hane);
        }

        return $hane;
    }

    public function test_hanedeki_es_hesaplari_ve_islemleri_gorur_ve_degistirir(): void
    {
        [$ben, $es, $yabanci] = User::factory()->count(3)->create()->all();
        $this->ayniHane($ben, $es);
        $hesap = Hesap::factory()->create(['user_id' => $ben->id, 'ad' => 'Yatırım']);
        $gram = $this->varlik('GRAM', 'altin', 3000);
        $islem = Islem::factory()->create(['hesap_id' => $hesap->id, 'user_id' => $ben->id, 'varlik_id' => $gram->id, 'tur' => 'alis', 'miktar' => 2, 'birim_fiyat' => 2800]);

        // Eş: listede, özette, işlemlerde görür; sahibi belli
        $this->actingAs($es)->getJson('/api/v1/hesaplar')->assertOk()
            ->assertJsonPath('hesaplar.0.ad', 'Yatırım')->assertJsonPath('hesaplar.0.sahip_id', $ben->id)
            ->assertJsonPath('hesaplar.0.sahip_ad', $ben->ad);
        $this->actingAs($es)->getJson('/api/v1/ozet')->assertOk()->assertJsonPath('toplam.deger', 6000);
        $this->actingAs($es)->getJson("/api/v1/ozet?hesap_id={$hesap->id}")->assertOk();
        $this->actingAs($es)->getJson("/api/v1/ozet/gecmis?hesap_id={$hesap->id}")->assertOk();
        $this->actingAs($es)->getJson('/api/v1/islemler')->assertOk()->assertJsonCount(1, 'islemler');
        $this->actingAs($es)->getJson("/api/v1/hesaplar/{$hesap->id}")->assertOk();

        // Eş benim hesabıma işlem girer, benim işlemimi düzeltir; ben de onunkini görürüm
        $this->actingAs($es)->postJson('/api/v1/islemler', [
            'hesap_id' => $hesap->id, 'varlik_id' => $gram->id, 'tur' => 'alis', 'miktar' => 1, 'birim_fiyat' => 2900, 'tarih' => now()->toDateString(),
        ])->assertCreated();
        $this->actingAs($es)->putJson("/api/v1/islemler/{$islem->id}", [
            'hesap_id' => $hesap->id, 'varlik_id' => $gram->id, 'tur' => 'alis', 'miktar' => 3, 'birim_fiyat' => 2800, 'tarih' => $islem->tarih->toDateString(),
        ])->assertOk();
        $this->actingAs($ben)->getJson('/api/v1/islemler')->assertJsonCount(2, 'islemler');
        $this->actingAs($es)->putJson("/api/v1/hesaplar/{$hesap->id}", ['ad' => 'Yatırım (ortak)', 'tur' => 'araci_kurum'])->assertOk();

        // Başka hane: hiçbir şey görmez, değiştiremez
        $this->actingAs($yabanci)->getJson('/api/v1/hesaplar')->assertOk()->assertJsonCount(0, 'hesaplar');
        $this->actingAs($yabanci)->getJson('/api/v1/islemler')->assertOk()->assertJsonCount(0, 'islemler');
        $this->actingAs($yabanci)->getJson('/api/v1/ozet')->assertOk()->assertJsonPath('toplam.deger', 0);
        $this->actingAs($yabanci)->getJson("/api/v1/hesaplar/{$hesap->id}")->assertForbidden();
        $this->actingAs($yabanci)->getJson("/api/v1/ozet?hesap_id={$hesap->id}")->assertJsonValidationErrors('hesap_id');
        $this->actingAs($yabanci)->deleteJson("/api/v1/islemler/{$islem->id}")->assertForbidden();
        $this->actingAs($yabanci)->postJson('/api/v1/islemler', [
            'hesap_id' => $hesap->id, 'varlik_id' => $gram->id, 'tur' => 'alis', 'miktar' => 1, 'birim_fiyat' => 1, 'tarih' => now()->toDateString(),
        ])->assertJsonValidationErrors('hesap_id');
        $this->actingAs($yabanci)->deleteJson("/api/v1/hesaplar/{$hesap->id}")->assertForbidden();
    }

    public function test_hanesiz_kullanicilar_birbirini_gormez_izleme_kisisel(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $hesap = Hesap::factory()->create(['user_id' => $a->id]);
        $this->actingAs($b)->getJson('/api/v1/hesaplar')->assertJsonCount(0, 'hesaplar');
        $this->actingAs($b)->getJson("/api/v1/hesaplar/{$hesap->id}")->assertForbidden();

        // Aynı hanede de izleme listesi kişiye özel
        $this->ayniHane($a, $b);
        $varlik = $this->varlik('USD', 'doviz', 32);
        $this->actingAs($a)->postJson('/api/v1/izleme', ['varlik_id' => $varlik->id])->assertSuccessful();
        $this->actingAs($b)->getJson('/api/v1/izleme')->assertOk()->assertJsonCount(0, 'varliklar');
    }

    public function test_aile_ucu_haneyi_kurar_uyeler_hane_icinde(): void
    {
        [$ben, $yabanci] = User::factory()->count(2)->create()->all();

        $cevap = $this->actingAs($ben)->getJson('/api/v1/aile/hane')->assertOk();
        $this->assertNotNull($ben->fresh()->hane_id);
        $this->assertSame($ben->id, $cevap->json('uyeler.0.user_id'));
        $this->assertSame($cevap->json('uyeler.0.id'), $cevap->json('ben_uye_id'));
        // İkinci çağrı yeni hane kurmaz
        $this->actingAs($ben)->getJson('/api/v1/aile/hane');
        $this->assertSame(1, Hane::count());

        $cocuk = $this->actingAs($ben)->postJson('/api/v1/aile/uyeler', ['ad' => 'Deniz'])->assertCreated()->json('uye');
        $this->actingAs($ben)->postJson('/api/v1/aile/uyeler', ['ad' => 'Deniz'])->assertJsonValidationErrors('ad');
        $this->actingAs($ben)->putJson('/api/v1/aile/hane', ['ad' => 'Bizim ev'])->assertOk()->assertJsonPath('hane.ad', 'Bizim ev');

        // Başka hane üyeyi göremez, değiştiremez, silemez; kendi hanesinde aynı ad serbest
        $this->actingAs($yabanci)->getJson('/api/v1/aile/hane')->assertOk()->assertJsonCount(1, 'uyeler');
        $this->actingAs($yabanci)->putJson("/api/v1/aile/uyeler/{$cocuk['id']}", ['ad' => 'X'])->assertForbidden();
        $this->actingAs($yabanci)->deleteJson("/api/v1/aile/uyeler/{$cocuk['id']}")->assertForbidden();
        $this->actingAs($yabanci)->postJson('/api/v1/aile/uyeler', ['ad' => 'Deniz'])->assertCreated();

        // Girişi olan üye silinemez; girişsiz kayıtsız üye silinir
        $benUye = HaneUyesi::where('user_id', $ben->id)->first();
        $this->actingAs($ben)->deleteJson("/api/v1/aile/uyeler/{$benUye->id}")->assertJsonValidationErrors('uye');
        $this->actingAs($ben)->deleteJson("/api/v1/aile/uyeler/{$cocuk['id']}")->assertOk();
        $this->assertDatabaseMissing('hane_uyeleri', ['id' => $cocuk['id']]);
    }

    public function test_aile_uclari_girissiz_kapali(): void
    {
        $this->getJson('/api/v1/aile/hane')->assertUnauthorized();
        $this->postJson('/api/v1/aile/uyeler', ['ad' => 'X'])->assertUnauthorized();
        $this->assertSame(0, Hane::count());
    }

    public function test_yonetici_kullaniciyi_haneye_baglar_kayitlar_sahipsiz_kalmaz(): void
    {
        $yonetici = User::factory()->yonetici()->create();
        // Adlar sabit: rastgele aynı ad gelirse üye adı "ad soyad" olur (HaneYonetimi) ve son denetim tutmaz
        [$ben, $es] = User::factory()->count(2)->sequence(['ad' => 'Ali'], ['ad' => 'Ayşe'])->create()->all();
        $haneler = app(HaneYonetimi::class);
        $hane = $haneler->hazirla($ben);
        $haneler->hazirla($es);
        $esinHesabi = Hesap::factory()->create(['user_id' => $es->id]);

        $this->actingAs($ben)->putJson("/api/v1/yonetim/kullanicilar/{$es->id}", ['hane' => $hane->id])->assertForbidden();
        $this->actingAs($yonetici)->getJson('/api/v1/yonetim/haneler')->assertOk()->assertJsonCount(2, 'haneler');
        $this->actingAs($yonetici)->putJson("/api/v1/yonetim/kullanicilar/{$es->id}", ['hane' => 999])->assertJsonValidationErrors('hane');

        // Eşin boş hanesi silinir, eş benim haneme geçer ve hesabı hanede görünür
        $this->actingAs($yonetici)->putJson("/api/v1/yonetim/kullanicilar/{$es->id}", ['hane' => $hane->id])->assertOk();
        $this->assertSame($hane->id, $es->fresh()->hane_id);
        $this->assertSame(1, Hane::count());
        $this->assertSame(2, HaneUyesi::where('hane_id', $hane->id)->count());
        $this->actingAs($ben)->getJson('/api/v1/hesaplar')->assertJsonPath('hesaplar.0.id', $esinHesabi->id);

        // Hanede kaydı (girişsiz üye) olan tek kullanıcı taşınamaz; başka kullanıcı varken taşınır, üye kaydı adıyla kalır
        HaneUyesi::create(['hane_id' => $hane->id, 'ad' => 'Deniz', 'aktif' => true]);
        $this->actingAs($yonetici)->putJson("/api/v1/yonetim/kullanicilar/{$es->id}", ['hane' => 'yeni'])->assertOk();
        $this->assertNotSame($hane->id, $es->fresh()->hane_id);
        $this->assertDatabaseHas('hane_uyeleri', ['hane_id' => $hane->id, 'user_id' => null, 'ad' => $es->ad]);
        $this->actingAs($yonetici)->putJson("/api/v1/yonetim/kullanicilar/{$ben->id}", ['hane' => 'yeni'])->assertJsonValidationErrors('hane_id');
        $this->assertSame($hane->id, $ben->fresh()->hane_id);
        $this->actingAs($yonetici)->getJson('/api/v1/yonetim/kullanicilar')->assertOk();
    }
}
