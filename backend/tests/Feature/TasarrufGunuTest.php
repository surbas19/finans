<?php

namespace Tests\Feature;

use App\Models\Hesap;
use App\Models\Islem;
use App\Models\Tasarruf;
use App\Models\TasarrufGunu;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Altın / döviz günü: her ay sabit miktar alım şablonu; alım kullanıcı onayıyla tasarruf kaydı olur (gun_id), kime
 * verildiği notta durur; başka hane göremez / değiştiremez.
 */
class TasarrufGunuTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private Varlik $altin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Evde']);
        $this->altin = $this->varlik('GRAM', 'altin', 3000);
        $this->actingAs($this->ben);
    }

    private function gunEkle(array $alanlar = []): int
    {
        return $this->postJson('/api/v1/aile/tasarruf-gunleri', [
            'ad' => 'Mahalle altın günü', 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 5,
            'ay_gunu' => 10, 'baslangic' => '2026-09-01', 'ay_sayisi' => 6, ...$alanlar,
        ])->assertCreated()->json('gun.id');
    }

    public function test_gun_eklenir_ayda_bekler_alim_onaylaninca_yapildi_olur_ve_tasarrufa_yazilir(): void
    {
        $id = $this->gunEkle();
        $this->getJson('/api/v1/aile/tasarruf-gunleri?donem=2026-11')->assertOk()
            ->assertJsonPath('gunler.0.miktar', '5')->assertJsonPath('gunler.0.varlik_ad', 'GRAM adı')
            ->assertJsonPath('ay.0.durum', 'bekliyor')->assertJsonPath('ay.0.vadesi_geldi', true)->assertJsonPath('ay.0.tarih', '2026-11-10');

        $ozet = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk();
        $ozet->assertJsonPath('gunler.0.durum', 'bekliyor')->assertJsonPath('tasarruf.toplam', 0);

        // Ay gelince kullanıcı onaylar: alış portföye yazılır, tasarrufa girer, kime verildiği notta
        $kayit = $this->postJson('/api/v1/aile/tasarruflar', [
            'hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 5, 'birim_fiyat' => 3000,
            'tarih' => '2026-11-10', 'gun_id' => $id, 'not' => 'Kasım: Ayşe teyzeye verildi',
        ])->assertCreated()->assertJsonPath('tasarruf.gun_id', $id)->assertJsonPath('tasarruf.gun_ad', 'Mahalle altın günü')
            ->assertJsonPath('tasarruf.tutar', 15000)->assertJsonPath('uyari', null);
        $this->assertSame('Kasım: Ayşe teyzeye verildi', Tasarruf::find($kayit->json('tasarruf.id'))->not);
        $this->assertSame(1, Islem::count());

        $this->getJson('/api/v1/aile/ozet?ay=2026-11')
            ->assertJsonPath('gunler.0.durum', 'yapildi')->assertJsonPath('gunler.0.vadesi_geldi', false)
            ->assertJsonPath('tasarruf.toplam', 15000)->assertJsonPath('tasarruf.kalemler.0.gun_ad', 'Mahalle altın günü');
    }

    public function test_gun_yalniz_suresi_icindeki_aylarda_gorunur(): void
    {
        $this->gunEkle(['baslangic' => '2026-09-01', 'ay_sayisi' => 2]); // Eylül, Ekim
        $this->getJson('/api/v1/aile/ozet?ay=2026-08')->assertJsonCount(0, 'gunler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-09')->assertJsonCount(1, 'gunler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonCount(1, 'gunler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonCount(0, 'gunler');
        // Süresiz gün
        $this->gunEkle(['ad' => 'Süresiz', 'ay_sayisi' => null]);
        $this->getJson('/api/v1/aile/ozet?ay=2027-03')->assertJsonCount(1, 'gunler')->assertJsonPath('gunler.0.gun.ad', 'Süresiz');
    }

    public function test_ayni_gun_icin_ikinci_alim_uyarir_gun_silinince_kayit_kalir(): void
    {
        $id = $this->gunEkle();
        $govde = ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 5, 'birim_fiyat' => 3000, 'tarih' => '2026-11-10', 'gun_id' => $id];
        $this->postJson('/api/v1/aile/tasarruflar', $govde)->assertCreated();
        $this->postJson('/api/v1/aile/tasarruflar', $govde)->assertCreated()->assertJsonPath('uyari', fn ($u): bool => is_string($u) && str_contains($u, 'zaten'));

        $this->deleteJson("/api/v1/aile/tasarruf-gunleri/{$id}")->assertOk();
        $this->assertSame(0, TasarrufGunu::count());
        $this->assertSame(2, Tasarruf::count());
        $this->assertNull(Tasarruf::first()->gun_id);
    }

    public function test_dogrulama_ay_gunu_miktar_bes_ve_baska_hanenin_hesabi(): void
    {
        $temel = ['ad' => 'Gün', 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 5, 'ay_gunu' => 10, 'baslangic' => '2026-11-01'];
        $this->postJson('/api/v1/aile/tasarruf-gunleri', [...$temel, 'ay_gunu' => 31])->assertStatus(422)->assertJsonValidationErrors('ay_gunu');
        $this->postJson('/api/v1/aile/tasarruf-gunleri', [...$temel, 'miktar' => 0])->assertStatus(422)->assertJsonValidationErrors('miktar');
        $bes = $this->varlik('BES1', 'bes', null);
        $this->postJson('/api/v1/aile/tasarruf-gunleri', [...$temel, 'varlik_id' => $bes->id])->assertStatus(422)->assertJsonValidationErrors('varlik_id');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $yabanciHesap = Hesap::factory()->create(['user_id' => $yabanci->id]);
        $this->postJson('/api/v1/aile/tasarruf-gunleri', [...$temel, 'hesap_id' => $yabanciHesap->id])->assertStatus(422)->assertJsonValidationErrors('hesap_id');
    }

    public function test_baska_hane_gunu_goremez_degistiremez_ve_alimina_baglayamaz(): void
    {
        $id = $this->gunEkle();

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $yabanciHesap = Hesap::factory()->create(['user_id' => $yabanci->id]);
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/tasarruf-gunleri?donem=2026-11')->assertOk()->assertJsonCount(0, 'gunler')->assertJsonCount(0, 'ay');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonCount(0, 'gunler');
        $this->putJson("/api/v1/aile/tasarruf-gunleri/{$id}", ['ad' => 'x', 'hesap_id' => $yabanciHesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 1, 'ay_gunu' => 1, 'baslangic' => '2026-11-01'])->assertForbidden();
        $this->deleteJson("/api/v1/aile/tasarruf-gunleri/{$id}")->assertForbidden();
        // Başka hanenin gününe tasarruf bağlanamaz
        $this->postJson('/api/v1/aile/tasarruflar', [
            'hesap_id' => $yabanciHesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 1, 'birim_fiyat' => 3000, 'tarih' => '2026-11-10', 'gun_id' => $id,
        ])->assertStatus(422)->assertJsonValidationErrors('gun_id');
        $this->assertSame(1, TasarrufGunu::count());
    }

    public function test_bizde_ay_alim_beklenmez_sira_bizde_gorunur_ve_sure_icinde_olmali(): void
    {
        $this->gunEkle(['baslangic' => '2026-09-01', 'ay_sayisi' => 6, 'bizde_ay' => '2026-11']);

        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gunler.0.durum', 'bekliyor')->assertJsonPath('gunler.0.vadesi_geldi', true);
        // Sıra bizde: alım beklenmez, vadesi gelmiş sayılmaz
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()
            ->assertJsonPath('gunler.0.durum', 'sira_bizde')->assertJsonPath('gunler.0.vadesi_geldi', false)
            ->assertJsonPath('gunler.0.gun.bizde_ay', '2026-11');

        // Aralık dışı ay reddedilir (başlangıçtan önce / süreden sonra)
        $this->postJson('/api/v1/aile/tasarruf-gunleri', ['ad' => 'x', 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 1, 'ay_gunu' => 5, 'baslangic' => '2026-09-01', 'ay_sayisi' => 2, 'bizde_ay' => '2026-12'])
            ->assertStatus(422)->assertJsonValidationErrors('bizde_ay');
        $this->postJson('/api/v1/aile/tasarruf-gunleri', ['ad' => 'x', 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 1, 'ay_gunu' => 5, 'baslangic' => '2026-09-01', 'bizde_ay' => '2026-08'])
            ->assertStatus(422)->assertJsonValidationErrors('bizde_ay');
        $this->postJson('/api/v1/aile/tasarruf-gunleri', ['ad' => 'x', 'hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 1, 'ay_gunu' => 5, 'baslangic' => '2026-09-01', 'bizde_ay' => 'kasım'])
            ->assertStatus(422)->assertJsonValidationErrors('bizde_ay');

        // Sıra bizde olan ay yine de alım girilirse yapıldı sayılır (engellenmez)
        $id = Tasarruf::count() === 0 ? TasarrufGunu::value('id') : 0;
        $this->postJson('/api/v1/aile/tasarruflar', ['hesap_id' => $this->hesap->id, 'varlik_id' => $this->altin->id, 'miktar' => 1, 'birim_fiyat' => 3000, 'tarih' => '2026-11-10', 'gun_id' => $id])->assertCreated();
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gunler.0.durum', 'yapildi');
    }
}
