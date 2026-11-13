<?php

namespace Tests\Feature;

use App\Enums\IslemTuru;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\YetersizMiktar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

class PortfoyHesabiTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private function islem(Hesap $hesap, int $varlikId, IslemTuru $tur, float $miktar, ?float $fiyat, int $gunOnce, float $komisyon = 0): Islem
    {
        return Islem::factory()->create([
            'hesap_id' => $hesap->id, 'user_id' => $hesap->user_id, 'varlik_id' => $varlikId, 'tur' => $tur,
            'miktar' => $miktar, 'birim_fiyat' => $fiyat, 'komisyon' => $komisyon, 'tarih' => now()->subDays($gunOnce)->toDateString(),
        ]);
    }

    public function test_agirlikli_ortalama_maliyet_ve_kismi_satis(): void
    {
        $hesap = Hesap::factory()->create();
        $hisse = $this->varlik('THYAO', fiyat: 319, degisim: 10);
        $this->islem($hesap, $hisse->id, IslemTuru::Alis, 100, 280, 30, 28);
        $this->islem($hesap, $hisse->id, IslemTuru::Alis, 50, 300, 20, 15);
        $this->islem($hesap, $hisse->id, IslemTuru::Satis, 30, 320, 10, 10);

        $p = app(PortfoyHesabi::class)->hesapla($hesap->user);
        $poz = $p['pozisyonlar'][0];

        $this->assertEqualsWithDelta(120, $poz['miktar'], 1e-9);
        $this->assertEqualsWithDelta(43043 / 150, $poz['ortalama_maliyet'], 1e-4);
        $this->assertEqualsWithDelta(34434.4, $poz['maliyet'], 0.01);
        $this->assertEqualsWithDelta(38280, $poz['deger'], 0.01);
        $this->assertEqualsWithDelta(3845.6, $poz['kz'], 0.01);
        $this->assertEqualsWithDelta(981.4, $poz['gerceklesen_kz'], 0.01); // 30×320−10 − 30×286,9533
        $this->assertFalse($poz['gerceklesen_kz_eksik']);
        // Günlük: fiyat %10 arttı → dünkü 290; 120 × 29
        $this->assertEqualsWithDelta(3480, $poz['gunluk_degisim'], 0.01);
        $this->assertEqualsWithDelta(38280, $p['toplam']['deger'], 0.01);
        $this->assertEqualsWithDelta(3845.6 / 34434.4 * 100, $p['toplam']['kz_yuzde'], 0.01);
    }

    public function test_maliyeti_bilinmeyen_kalem_sifir_maliyet_sayilmaz(): void
    {
        $hesap = Hesap::factory()->create();
        $altin = $this->varlik('CEYREK', 'altin', 10000);
        $this->islem($hesap, $altin->id, IslemTuru::Mevcut, 4, null, 100);

        $poz = app(PortfoyHesabi::class)->hesapla($hesap->user)['pozisyonlar'][0];
        $this->assertEqualsWithDelta(40000, $poz['deger'], 0.01);
        $this->assertNull($poz['kz']);
        $this->assertNull($poz['maliyet']);
        $this->assertNull($poz['ortalama_maliyet']);

        // Bilinenle karışınca kâr / zarar yalnızca bilinen kısım için; satışta bilinmeyen kısmın kârı eksik işaretlenir
        $this->islem($hesap, $altin->id, IslemTuru::Alis, 4, 9000, 50);
        $this->islem($hesap, $altin->id, IslemTuru::Satis, 4, 9500, 10);
        $p = app(PortfoyHesabi::class)->hesapla($hesap->user);
        $poz = $p['pozisyonlar'][0];
        $this->assertEqualsWithDelta(4, $poz['miktar'], 1e-9);
        $this->assertEqualsWithDelta(2, $poz['maliyeti_bilinmeyen_miktar'], 1e-9);
        $this->assertEqualsWithDelta(18000, $poz['maliyet'], 0.01);
        $this->assertEqualsWithDelta(2000, $poz['kz'], 0.01); // 2 × 10000 − 18000
        $this->assertEqualsWithDelta(1000, $poz['gerceklesen_kz'], 0.01); // 2 × 9500 − 2 × 9000
        $this->assertTrue($poz['gerceklesen_kz_eksik']);
        $this->assertTrue($p['toplam']['maliyeti_eksik']);
    }

    public function test_nakit_birimi_bir_tl_kar_zarari_yok(): void
    {
        $hesap = Hesap::factory()->create();
        $nakit = $this->varlik('TL', 'nakit', null, kaynak: 'sabit'); // fiyat satırı yok
        $this->islem($hesap, $nakit->id, IslemTuru::Mevcut, 10000, 1, 30);
        $this->islem($hesap, $nakit->id, IslemTuru::Alis, 5000, 1, 20);
        $this->islem($hesap, $nakit->id, IslemTuru::Satis, 3000, 1, 10);

        $p = app(PortfoyHesabi::class)->hesapla($hesap->user);
        $poz = $p['pozisyonlar'][0];
        $this->assertEqualsWithDelta(12000, $poz['deger'], 0.01);
        $this->assertEqualsWithDelta(1, $poz['fiyat_tl'], 1e-9);
        $this->assertEqualsWithDelta(0, $poz['kz'], 0.01);
        $this->assertNull($poz['gunluk_degisim']);
        $this->assertSame(0, $p['toplam']['fiyatsiz']);
        $this->assertFalse($p['toplam']['maliyeti_eksik']);
        $this->assertSame([['tur' => 'nakit', 'etiket' => 'Nakit', 'deger' => 12000.0]], $p['dagilim']);
    }

    public function test_bes_guncel_tutari_oncekinin_yerine_gecer_kar_yuzdesinden_maliyet(): void
    {
        $hesap = Hesap::factory()->create();
        $bes = $this->varlik('BES', 'bes', null, kaynak: 'sabit');
        $this->islem($hesap, $bes->id, IslemTuru::Deger, 120000, 1 / 1.2, 300);
        $this->islem($hesap, $bes->id, IslemTuru::Deger, 150000, 0.8, 10); // %25 kâr

        $p = app(PortfoyHesabi::class)->hesapla($hesap->user);
        $poz = $p['pozisyonlar'][0];
        $this->assertEqualsWithDelta(150000, $poz['miktar'], 1e-6);
        $this->assertEqualsWithDelta(150000, $poz['deger'], 0.01);
        $this->assertEqualsWithDelta(120000, $poz['maliyet'], 0.01);
        $this->assertEqualsWithDelta(30000, $poz['kz'], 0.01);
        $this->assertEqualsWithDelta(25, $poz['kz_yuzde'], 0.01);
        $this->assertSame('Bireysel emeklilik', $p['dagilim'][0]['etiket']);

        // Zarar (eksi yüzde)
        $this->islem($hesap, $bes->id, IslemTuru::Deger, 90000, 1 / 0.9, 0);
        $poz = app(PortfoyHesabi::class)->hesapla($hesap->user)['pozisyonlar'][0];
        $this->assertEqualsWithDelta(100000, $poz['maliyet'], 0.01);
        $this->assertEqualsWithDelta(-10000, $poz['kz'], 0.01);

        // Kâr yüzdesi yoksa maliyet bilinmiyor: K/Z yok, sıfır maliyet sayılmaz
        $baska = Hesap::factory()->create(['user_id' => $hesap->user_id]);
        $this->islem($baska, $bes->id, IslemTuru::Deger, 50000, null, 0);
        $p = app(PortfoyHesabi::class)->hesapla($hesap->user, $baska->id);
        $poz = $p['pozisyonlar'][0];
        $this->assertEqualsWithDelta(50000, $poz['deger'], 0.01);
        $this->assertNull($poz['kz']);
        $this->assertNull($poz['maliyet']);
        $this->assertTrue($p['toplam']['maliyeti_eksik']);
    }

    public function test_usd_fiyatli_varlik_kurla_tl_ye_cevrilir(): void
    {
        $hesap = Hesap::factory()->create();
        $this->varlik('USD', 'doviz', 50);
        $ons = $this->varlik('ONS', 'altin', 4000, 'USD');
        $this->islem($hesap, $ons->id, IslemTuru::Alis, 1, 150000, 10);

        $poz = app(PortfoyHesabi::class)->hesapla($hesap->user)['pozisyonlar'][0];
        $this->assertEqualsWithDelta(200000, $poz['fiyat_tl'], 0.01);
        $this->assertEqualsWithDelta(50000, $poz['kz'], 0.01);
    }

    public function test_fiyati_olmayan_varlik_degersiz_sayilir_toplama_girmez(): void
    {
        $hesap = Hesap::factory()->create();
        $fon = $this->varlik('AFT', 'fon', null);
        $this->islem($hesap, $fon->id, IslemTuru::Alis, 100, 2, 10);

        $p = app(PortfoyHesabi::class)->hesapla($hesap->user);
        $this->assertNull($p['pozisyonlar'][0]['deger']);
        $this->assertSame(1, $p['toplam']['fiyatsiz']);
        $this->assertEquals(0, $p['toplam']['deger']);
    }

    public function test_hesaplar_ayri_ve_varlik_bazinda_toplu(): void
    {
        $user = User::factory()->create();
        $a = Hesap::factory()->create(['user_id' => $user->id, 'ad' => 'A']);
        $b = Hesap::factory()->create(['user_id' => $user->id, 'ad' => 'B']);
        $gram = $this->varlik('GRAM', 'altin', 6000);
        $this->islem($a, $gram->id, IslemTuru::Alis, 10, 5000, 10);
        $this->islem($b, $gram->id, IslemTuru::Alis, 5, 4000, 10);

        $p = app(PortfoyHesabi::class)->hesapla($user);
        $this->assertEqualsWithDelta(60000, collect($p['hesaplar'])->firstWhere('ad', 'A')['deger'], 0.01);
        $this->assertEqualsWithDelta(30000, collect($p['hesaplar'])->firstWhere('ad', 'B')['deger'], 0.01);
        $toplu = $p['varliklar'][0];
        $this->assertEqualsWithDelta(15, $toplu['miktar'], 1e-9);
        $this->assertSame(2, $toplu['hesap_sayisi']);
        $this->assertEqualsWithDelta(70000 / 15, $toplu['ortalama_maliyet'], 1e-4);
        $this->assertEqualsWithDelta(90000, $p['toplam']['deger'], 0.01);
    }

    public function test_eldekinden_fazla_satis_reddedilir(): void
    {
        $hesap = Hesap::factory()->create();
        $hisse = $this->varlik('ASELS');
        $alis = Islem::factory()->make(['hesap_id' => $hesap->id, 'varlik_id' => $hisse->id, 'miktar' => 5, 'tarih' => now()->subDays(5)]);
        $satis = Islem::factory()->make(['hesap_id' => $hesap->id, 'varlik_id' => $hisse->id, 'tur' => IslemTuru::Satis, 'miktar' => 6, 'tarih' => now()->subDays(1)]);

        $this->expectException(YetersizMiktar::class);
        app(PortfoyHesabi::class)->dogrula(collect([$satis, $alis]));
    }
}
