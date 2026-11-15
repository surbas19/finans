<?php

namespace Tests\Feature;

use App\Enums\IslemTuru;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Aile\HaneYonetimi;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Yatırımı başka hesaba aktar: kaynakta satış, hedefte alış; maliyet taşınır, toplam ve gerçekleşen K/Z değişmez.
 */
class PozisyonAktarimTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $kaynak;

    private Hesap $hedef;

    private Varlik $gram;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->kaynak = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Evde']);
        $this->hedef = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Banka']);
        $this->gram = $this->varlik('GRAM', 'altin', 3000);
        $this->actingAs($this->ben);
    }

    private function islem(Hesap $hesap, IslemTuru $tur, float $miktar, ?float $fiyat, string $tarih = '2026-10-01'): Islem
    {
        return Islem::create(['user_id' => $hesap->user_id, 'hesap_id' => $hesap->id, 'varlik_id' => $this->gram->id, 'tur' => $tur, 'miktar' => $miktar, 'birim_fiyat' => $fiyat, 'komisyon' => 0, 'tarih' => $tarih]);
    }

    private function aktar(float $miktar, array $ek = [])
    {
        return $this->postJson("/api/v1/hesaplar/{$this->kaynak->id}/pozisyonlar/{$this->gram->id}/aktar", ['hedef_hesap_id' => $this->hedef->id, 'miktar' => $miktar, 'tarih' => '2026-11-10', ...$ek]);
    }

    private function poz(Hesap $hesap): ?array
    {
        return collect(app(PortfoyHesabi::class)->hesapla($this->ben, $hesap->id)['pozisyonlar'])->firstWhere('varlik.id', $this->gram->id);
    }

    public function test_kismi_aktarim_miktar_ve_maliyeti_tasir_toplam_ve_gerceklesen_kz_degismez(): void
    {
        $this->islem($this->kaynak, IslemTuru::Alis, 6, 2000);
        $this->islem($this->kaynak, IslemTuru::Alis, 4, 2500); // 10 gr, ortalama maliyet 2.200
        $once = app(PortfoyHesabi::class)->hesapla($this->ben);

        $this->aktar(4, ['not' => 'Kasaya'])->assertCreated()->assertJsonPath('aktarim.kaynak_miktar', 4);

        $k = $this->poz($this->kaynak);
        $h = $this->poz($this->hedef);
        $this->assertEqualsWithDelta(6, $k['miktar'], 1e-9);
        $this->assertEqualsWithDelta(13200, $k['maliyet'], 0.01);  // 6 × 2.200
        $this->assertEqualsWithDelta(4, $h['miktar'], 1e-9);
        $this->assertEqualsWithDelta(8800, $h['maliyet'], 0.01);   // maliyet taşındı: 4 × 2.200
        $this->assertEqualsWithDelta(2200, $h['ortalama_maliyet'], 1e-6);

        $sonra = app(PortfoyHesabi::class)->hesapla($this->ben);
        $this->assertEqualsWithDelta($once['toplam']['deger'], $sonra['toplam']['deger'], 0.01);
        $this->assertEqualsWithDelta($once['toplam']['maliyet'], $sonra['toplam']['maliyet'], 0.01);
        $this->assertEqualsWithDelta($once['toplam']['kz'], $sonra['toplam']['kz'], 0.01);
        $this->assertEqualsWithDelta(0, $sonra['toplam']['gerceklesen_kz'], 0.01); // satış fiyatı = ortalama maliyet

        // Eşleşik kayıtlar aynı kodla; işlem listesinde görünür
        $kodlar = Islem::whereNotNull('aktarim_kodu')->pluck('aktarim_kodu')->unique();
        $this->assertCount(1, $kodlar);
        $this->assertSame(2, Islem::whereNotNull('aktarim_kodu')->count());
        $this->assertStringContainsString('Aktarım: Evde → Banka · Kasaya', Islem::where('tur', 'satis')->value('not'));
        $this->getJson('/api/v1/islemler')->assertOk()->assertJsonPath('islemler.0.aktarim_kodu', $kodlar->first());
    }

    public function test_tum_pozisyon_aktarilinca_kaynakta_kalmaz_maliyeti_bilinmeyen_kisim_fiyatsiz_gider(): void
    {
        $this->islem($this->kaynak, IslemTuru::Alis, 3, 2000);
        $this->islem($this->kaynak, IslemTuru::Mevcut, 1, null); // maliyeti bilinmeyen 1 gr

        $this->aktar(4)->assertCreated()->assertJsonPath('aktarim.bilinen', 3)->assertJsonPath('aktarim.bilinmeyen', 1);

        $this->assertNull($this->poz($this->kaynak)); // açık pozisyon kalmadı
        $h = $this->poz($this->hedef);
        $this->assertEqualsWithDelta(4, $h['miktar'], 1e-9);
        $this->assertEqualsWithDelta(1, $h['maliyeti_bilinmeyen_miktar'], 1e-9); // sıfır maliyet sayılmadı
        $this->assertEqualsWithDelta(6000, $h['maliyet'], 0.01);
        $this->assertSame(['alis', 'mevcut'], Islem::where('hesap_id', $this->hedef->id)->orderBy('id')->pluck('tur')->map(fn ($t) => $t->value)->all());
    }

    public function test_hariç_bayragi_hedefe_kopyalanir(): void
    {
        $this->islem($this->kaynak, IslemTuru::Alis, 2, 2000);
        $this->putJson("/api/v1/hesaplar/{$this->kaynak->id}/pozisyonlar/{$this->gram->id}", ['dahil' => false])->assertOk();
        $this->aktar(2)->assertCreated();
        $this->assertFalse($this->poz($this->hedef)['dahil']);
    }

    public function test_dogrulama_fazla_miktar_ayni_hesap_ileri_tarih_bes(): void
    {
        $this->islem($this->kaynak, IslemTuru::Alis, 2, 2000);
        $this->aktar(3)->assertStatus(422)->assertJsonValidationErrors('miktar');
        $this->postJson("/api/v1/hesaplar/{$this->kaynak->id}/pozisyonlar/{$this->gram->id}/aktar", ['hedef_hesap_id' => $this->kaynak->id, 'miktar' => 1, 'tarih' => '2026-11-10'])
            ->assertStatus(422)->assertJsonValidationErrors('hedef_hesap_id');
        $this->aktar(1, ['tarih' => '2026-11-20'])->assertStatus(422)->assertJsonValidationErrors('tarih');
        $this->aktar(0)->assertStatus(422)->assertJsonValidationErrors('miktar');
        // Aktarım tarihinden sonraki satış bozulamaz
        $this->islem($this->kaynak, IslemTuru::Satis, 2, 3000, '2026-11-12');
        $this->aktar(1, ['tarih' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('miktar');

        $bes = $this->varlik('BES1', 'bes', null);
        $this->postJson("/api/v1/hesaplar/{$this->kaynak->id}/pozisyonlar/{$bes->id}/aktar", ['hedef_hesap_id' => $this->hedef->id, 'miktar' => 1, 'tarih' => '2026-11-10'])
            ->assertStatus(422)->assertJsonValidationErrors('miktar');
        $this->assertSame(0, Islem::whereNotNull('aktarim_kodu')->count());
    }

    public function test_aktarim_kaydi_duzenlenmez_silinince_eslesik_kayitlar_birlikte_gider(): void
    {
        $this->islem($this->kaynak, IslemTuru::Alis, 5, 2000);
        $this->aktar(2)->assertCreated();
        $satis = Islem::where('tur', 'satis')->first();
        $this->putJson("/api/v1/islemler/{$satis->id}", ['hesap_id' => $this->kaynak->id, 'varlik_id' => $this->gram->id, 'tur' => 'satis', 'miktar' => 1, 'birim_fiyat' => 100, 'tarih' => '2026-11-10'])
            ->assertStatus(422)->assertJsonValidationErrors('islem');

        // Hedefteki alışı silince kaynaktaki satış da silinir; hedef yeniden satış yapmışsa silinemez
        $alis = Islem::where('hesap_id', $this->hedef->id)->first();
        $this->islem($this->hedef, IslemTuru::Satis, 2, 3000, '2026-11-12');
        $this->deleteJson("/api/v1/islemler/{$alis->id}")->assertStatus(422);
        Islem::where('hesap_id', $this->hedef->id)->where('tur', 'satis')->delete();
        $this->deleteJson("/api/v1/islemler/{$alis->id}")->assertOk()->assertJsonPath('message', 'Aktarım ve eşleşik kayıtlar silindi.');
        $this->assertSame(0, Islem::whereNotNull('aktarim_kodu')->count());
        $this->assertEqualsWithDelta(5, $this->poz($this->kaynak)['miktar'], 1e-9);
    }

    public function test_baska_hane_aktaramaz_ve_baska_hanenin_hesabina_aktarilamaz(): void
    {
        $this->islem($this->kaynak, IslemTuru::Alis, 2, 2000);
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $yabanciHesap = Hesap::factory()->create(['user_id' => $yabanci->id]);

        $this->aktar(1, ['hedef_hesap_id' => $yabanciHesap->id])->assertStatus(422)->assertJsonValidationErrors('hedef_hesap_id');

        $this->actingAs($yabanci);
        $this->postJson("/api/v1/hesaplar/{$this->kaynak->id}/pozisyonlar/{$this->gram->id}/aktar", ['hedef_hesap_id' => $yabanciHesap->id, 'miktar' => 1, 'tarih' => '2026-11-10'])->assertForbidden();
        $this->assertSame(0, Islem::whereNotNull('aktarim_kodu')->count());
    }
}
