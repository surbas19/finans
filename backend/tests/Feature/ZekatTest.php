<?php

namespace Tests\Feature;

use App\Enums\EkstreDurumu;
use App\Enums\IslemTuru;
use App\Models\Ekstre;
use App\Models\Fatura;
use App\Models\FaturaTuru;
use App\Models\Fiyat;
use App\Models\Gelir;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Models\ZekatAyari;
use App\Models\ZekatYili;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Zekât: ayar, hesap (nisab, tür kuralları, hesap bayrağı, borç düşme), yıl kaydı (değişmez anlık görüntü),
 * ödemeler, hatırlatma, Bütçe bilgi satırı, hane izolasyonu. Fiyatlar elle `Fiyat` kaydıyla; gerçek kaynak yok.
 */
class ZekatTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $hesap;

    private Varlik $gram;

    private Varlik $nakit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->hesap = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Yatırım']);
        $this->gram = $this->varlik('GRAM', 'altin', 6300); // nisab: 80,18 gr × 6300 = 505.134 TL
        $this->nakit = $this->varlik('TRY-NAKIT', 'nakit', null, kaynak: 'sabit');
        $this->actingAs($this->ben);
    }

    private function islem(Varlik $varlik, float $miktar, float $birim = 1, ?Hesap $hesap = null, IslemTuru $tur = IslemTuru::Alis): Islem
    {
        $hesap ??= $this->hesap;

        return Islem::create([
            'user_id' => $hesap->user_id, 'hesap_id' => $hesap->id, 'varlik_id' => $varlik->id, 'tur' => $tur,
            'miktar' => $miktar, 'birim_fiyat' => $birim, 'komisyon' => 0, 'tarih' => '2026-10-01',
        ]);
    }

    private function nakit(float $tl, ?Hesap $hesap = null): void
    {
        $this->islem($this->nakit, $tl, 1, $hesap);
    }

    /** @return TestResponse<JsonResponse> */
    private function hesapla(): TestResponse
    {
        return $this->postJson('/api/v1/aile/zekat/hesapla')->assertOk();
    }

    private function ayar(array $alanlar): void
    {
        $this->putJson('/api/v1/aile/zekat/ayar', $alanlar)->assertOk();
    }

    private function hane(User ...$kullanicilar): void
    {
        $haneler = app(HaneYonetimi::class);
        foreach ($kullanicilar as $u) {
            $haneler->tasi($u, $this->ben->hane);
        }
    }

    public function test_varsayilan_ayar_altin_nisabi_hepsi_dahil_borc_dusme_kapali(): void
    {
        $r = $this->getJson('/api/v1/aile/zekat/ayar')->assertOk()
            ->assertJsonPath('ayar.nisab_olcutu', 'altin')->assertJsonPath('ayar.nisab_altin_gram', 80.18)
            ->assertJsonPath('ayar.nisab_gumus_gram', 561)->assertJsonPath('ayar.oran_binde', 25)
            ->assertJsonPath('ayar.borc_dus', false)->assertJsonPath('ayar.zekat_gunu_turu', 'hicri')
            ->assertJsonPath('ayar.tur_kurallari.bes.dahil', true)->assertJsonPath('ayar.tur_kurallari.bes.yuzde', 100)
            ->assertJsonPath('zekat_gunu.tarih', '2027-02-08')->assertJsonPath('zekat_gunu.tahmini', true)
            ->assertJsonPath('uyari', 'Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın.')
            ->assertJsonPath('hesaplar.0.ad', 'Yatırım')->assertJsonPath('hesaplar.0.zekata_dahil', true);
        $this->assertSame(7, count($r->json('ayar.tur_kurallari')));
        // Okumak satır yazmaz (hane "kaydı var" sayılmaz)
        $this->assertDatabaseCount('zekat_ayarlari', 0);
    }

    public function test_nisab_alti_zekat_yok_ustu_kurus_yuvarlamali_zekat(): void
    {
        $this->nakit(100000);
        $this->hesapla()->assertJsonPath('brut', 100000)->assertJsonPath('nisab.tl', 505134)->assertJsonPath('nisab_ustu', false)
            ->assertJsonPath('zekat', 0)->assertJsonPath('uyari', 'Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın.');

        // 600.000,33 × %2,5 = 15.000,00825 → 15.000,01
        $this->nakit(500000.33);
        $this->hesapla()->assertJsonPath('brut', 600000.33)->assertJsonPath('nisab_ustu', true)->assertJsonPath('zekat', 15000.01);

        // 600.000,19 × %2,5 = 15.000,00475 → 15.000,00 (yarım kuruşun altı aşağı)
        Islem::query()->delete();
        $this->nakit(600000.19);
        $this->hesapla()->assertJsonPath('zekat', 15000);

        // 600.000,20 × %2,5 = 15.000,005: yarım kuruş yukarı
        Islem::query()->delete();
        $this->nakit(600000.20);
        $this->hesapla()->assertJsonPath('zekat', 15000.01);

        // Tam nisab değerinde zekât gerekir
        Islem::query()->delete();
        $this->nakit(505134);
        $this->hesapla()->assertJsonPath('nisab_ustu', true)->assertJsonPath('zekat', 12628.35);
    }

    public function test_usd_ve_altin_degerleme_fiyatsiz_kalem_eksiklerde_sifir_sayilmaz(): void
    {
        $this->varlik('USD', 'doviz', 40);
        $ons = $this->varlik('ONS', 'altin', 2000, 'USD');
        $hisse = $this->varlik('YOKFIYAT', 'hisse', null);
        $this->islem($ons, 2, 1900); // 2 × 2000 × 40 = 160.000
        $this->islem($this->gram, 100, 5000); // 630.000
        $this->islem($hisse, 10, 50);

        $r = $this->hesapla()->assertJsonPath('brut', 790000)->assertJsonPath('eksik_veri', true)
            ->assertJsonPath('usd_kuru', 40)->assertJsonCount(1, 'eksikler')->assertJsonPath('eksikler.0.varlik_kod', 'YOKFIYAT')
            ->assertJsonPath('zekat', 19750);
        $kalem = collect($r->json('kalemler'))->firstWhere('varlik_kod', 'YOKFIYAT');
        $this->assertNull($kalem['deger']);
        $this->assertSame(0, (int) $kalem['hesaba_giren']);
    }

    public function test_usd_kuru_yoksa_dolar_fiyatli_kalem_eksik_olur(): void
    {
        $ons = $this->varlik('ONS', 'altin', 2000, 'USD');
        $this->islem($ons, 2, 1900);
        $this->hesapla()->assertJsonPath('brut', 0)->assertJsonPath('eksik_veri', true)->assertJsonPath('eksikler.0.varlik_kod', 'ONS');
    }

    public function test_nisab_fiyati_yoksa_hesap_hata_verir_uydurulmaz(): void
    {
        Fiyat::where('varlik_id', $this->gram->id)->delete();
        $this->nakit(900000);
        $this->postJson('/api/v1/aile/zekat/hesapla')->assertStatus(422)->assertJsonValidationErrors('nisab');
        $this->postJson('/api/v1/aile/zekat/yillar', [])->assertStatus(422);
        $this->assertDatabaseCount('zekat_yillari', 0);
    }

    public function test_gumus_olcutu_ve_gram_ayari(): void
    {
        $this->varlik('GUMUS', 'altin', 80);
        $this->nakit(50000);
        $this->hesapla()->assertJsonPath('nisab_ustu', false);

        $this->ayar(['nisab_olcutu' => 'gumus']);
        // 561 gr × 80 = 44.880 TL
        $this->hesapla()->assertJsonPath('nisab.olcut', 'gumus')->assertJsonPath('nisab.gram', 561)->assertJsonPath('nisab.tl', 44880)
            ->assertJsonPath('nisab_ustu', true)->assertJsonPath('zekat', 1250);

        $this->ayar(['nisab_gumus_gram' => 700.5, 'oran_binde' => 50]);
        $this->hesapla()->assertJsonPath('nisab.tl', 56040)->assertJsonPath('nisab_ustu', false);
    }

    public function test_tur_kurallari_hisse_kapali_bes_tam_tutar_kapsam_yuzdesi(): void
    {
        $hisse = $this->varlik('THYAO', 'hisse', 300);
        $fon = $this->varlik('AFT', 'fon', 10);
        $bes = $this->varlik('BES', 'bes', null, kaynak: 'sabit');
        $this->islem($hisse, 1000, 250); // 300.000
        $this->islem($fon, 10000, 8); // 100.000
        $this->islem($bes, 200000, 1 / 1.25, tur: IslemTuru::Deger); // 200.000 tam tutar (kâr dahil)
        $this->nakit(10000);

        $this->hesapla()->assertJsonPath('brut', 610000);

        $this->ayar(['tur_kurallari' => ['hisse' => ['dahil' => false, 'yuzde' => 100], 'fon' => ['dahil' => true, 'yuzde' => 50]]]);
        $r = $this->hesapla()->assertJsonPath('brut', 260000); // 200.000 BES + 50.000 fon + 10.000 nakit
        $thyao = collect($r->json('kalemler'))->firstWhere('varlik_kod', 'THYAO');
        $this->assertFalse($thyao['dahil_edilen']);
        $this->assertSame(300000, (int) $thyao['deger']);
        $this->assertSame('Bu varlık türü zekât hesabına dahil değil', $thyao['dislanma_nedeni']);
        // BES'in maliyeti (kâr yüzdesi) girildi: not yok; tam tutar sayılır
        $this->assertSame(200000, (int) collect($r->json('kalemler'))->firstWhere('varlik_kod', 'BES')['hesaba_giren']);

        $this->putJson('/api/v1/aile/zekat/ayar', ['tur_kurallari' => ['yok_tur' => ['dahil' => true, 'yuzde' => 100]]])->assertStatus(422);
        $this->putJson('/api/v1/aile/zekat/ayar', ['tur_kurallari' => ['fon' => ['dahil' => true, 'yuzde' => 150]]])->assertStatus(422);
    }

    public function test_hesap_bayragi_ve_esin_hesabi_hane_toplamina_dahil(): void
    {
        $es = User::factory()->create();
        $this->hane($es);
        $esHesap = Hesap::factory()->create(['user_id' => $es->id, 'ad' => 'Eşimin hesabı']);
        $this->nakit(300000);
        $this->nakit(250000, $esHesap);

        $this->hesapla()->assertJsonPath('brut', 550000);
        // Eş de aynı hane toplamını görür
        $this->actingAs($es)->postJson('/api/v1/aile/zekat/hesapla')->assertOk()->assertJsonPath('brut', 550000);

        // Eşin hesabı bayrakla dışlanır (kalem listede durur, hesaba girmez)
        $this->actingAs($this->ben)->ayar(['hesaplar' => [['id' => $esHesap->id, 'zekata_dahil' => false]]]);
        $r = $this->hesapla()->assertJsonPath('brut', 300000);
        $kalem = collect($r->json('kalemler'))->firstWhere('hesap_ad', 'Eşimin hesabı');
        $this->assertFalse($kalem['dahil_edilen']);
        $this->assertSame('Hesap zekât hesabına dahil değil', $kalem['dislanma_nedeni']);
        $this->assertFalse(Hesap::find($esHesap->id)->zekata_dahil);
        $this->getJson('/api/v1/hesaplar')->assertOk()->assertJsonPath('hesaplar.1.zekata_dahil', false);

        // Mevcut hesap ucuyla da değişir
        $this->putJson("/api/v1/hesaplar/{$esHesap->id}", ['ad' => 'Eşimin hesabı', 'tur' => 'araci_kurum', 'zekata_dahil' => true, 'zekat_notu' => 'Çocuğa emanet değil'])
            ->assertOk()->assertJsonPath('hesap.zekata_dahil', true)->assertJsonPath('hesap.zekat_notu', 'Çocuğa emanet değil');
        $this->hesapla()->assertJsonPath('brut', 550000);
    }

    public function test_borc_dusme_kapaliyken_dusmez_acikken_odenmemis_ekstre_vadesi_gelen_fatura_elle_borc_dusulur(): void
    {
        $haneId = $this->ben->hane_id;
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'Bonus'])->json('kart.id');
        $ekstre = fn (string $borc, bool $odendi, EkstreDurumu $durum = EkstreDurumu::Onaylandi): Ekstre => Ekstre::create([
            'hane_id' => $haneId, 'kart_id' => $kart, 'durum' => $durum, 'kesim_tarihi' => '2026-11-10', 'son_odeme_tarihi' => '2026-11-20',
            'donem_borcu' => $borc, 'odendi' => $odendi,
        ]);
        $ekstre('40000.00', false);
        $ekstre('7777.00', true); // ödenmiş: düşmez
        $ekstre('8888.00', false, EkstreDurumu::Taslak); // onaysız: düşmez

        $sag = $this->postJson('/api/v1/aile/saglayicilar', ['ad' => 'İSKİ', 'fatura_turu_id' => FaturaTuru::whereNull('hane_id')->where('ad', 'Su')->value('id')])->json('saglayici.id');
        $fatura = fn (string $tutar, ?string $vade, string $durum = 'bekliyor'): Fatura => Fatura::create([
            'hane_id' => $haneId, 'saglayici_id' => $sag, 'fatura_turu_id' => FaturaTuru::whereNull('hane_id')->where('ad', 'Su')->value('id'),
            'tutar' => $tutar, 'son_odeme_tarihi' => $vade, 'durum' => $durum, 'kaynak' => 'elle',
        ]);
        $fatura('1500.00', '2026-11-10'); // vadesi gelmiş
        $fatura('999.00', '2026-12-10'); // vadesi gelmemiş: düşmez
        $fatura('555.00', '2026-11-01', 'odendi'); // ödenmiş: düşmez

        $this->postJson('/api/v1/aile/zekat/borclar', ['ad' => 'Arkadaşa borç', 'tutar' => 10000.5])->assertCreated()->assertJsonPath('borc.tutar', 10000.5);
        $this->nakit(600000);

        // Kapalıyken borç düşmez
        $this->hesapla()->assertJsonPath('borc', 0)->assertJsonPath('net', 600000)->assertJsonPath('zekat', 15000);

        $this->ayar(['borc_dus' => true]);
        $r = $this->hesapla()->assertJsonPath('borc', 51500.5)->assertJsonPath('net', 548499.5)->assertJsonPath('zekat', 13712.49)
            ->assertJsonPath('brut', 600000)->assertJsonCount(3, 'borclar');
        $this->assertEqualsCanonicalizing(['kart', 'fatura', 'elle'], collect($r->json('borclar'))->pluck('kaynak')->all());

        // Kaynak bazlı kapatma
        $this->ayar(['borc_kaynaklari' => ['kart' => false]]);
        $this->hesapla()->assertJsonPath('borc', 11500.5);

        // Net sıfırın altına inmez
        $this->postJson('/api/v1/aile/zekat/borclar', ['ad' => 'Büyük borç', 'tutar' => 9000000])->assertCreated();
        $this->hesapla()->assertJsonPath('net', 0)->assertJsonPath('nisab_ustu', false)->assertJsonPath('zekat', 0);
    }

    public function test_baska_hanenin_ekstre_fatura_borc_ve_hesabi_hesaba_karismaz(): void
    {
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $yHesap = Hesap::factory()->create(['user_id' => $yabanci->id]);
        $this->islem($this->nakit, 999999, 1, $yHesap);
        $this->actingAs($yabanci)->postJson('/api/v1/aile/zekat/borclar', ['ad' => 'Yabancı borcu', 'tutar' => 5000])->assertCreated();
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'X'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $yabanci->id)->value('id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'K'])->json('kart.id');
        Ekstre::create(['hane_id' => $yabanci->hane_id, 'kart_id' => $kart, 'durum' => EkstreDurumu::Onaylandi, 'kesim_tarihi' => '2026-11-10', 'donem_borcu' => '70000', 'odendi' => false]);

        $this->actingAs($this->ben)->ayar(['borc_dus' => true]);
        $this->nakit(600000);
        $this->hesapla()->assertJsonPath('brut', 600000)->assertJsonPath('borc', 0)->assertJsonCount(0, 'borclar');
        $this->getJson('/api/v1/aile/zekat/borclar')->assertJsonCount(0, 'borclar');
    }

    public function test_yil_kaydi_degismez_ayni_gun_ikinci_kayit_reddedilir_kesinlesen_duzenlenemez(): void
    {
        $this->nakit(600000);
        $this->islem($this->gram, 10, 5000); // 63.000
        $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => '2026-11-15', 'not' => 'ilk'])->assertCreated()
            ->assertJsonPath('yil.durum', 'taslak')->assertJsonPath('yil.brut', 663000)->assertJsonPath('yil.zekat', 16575)
            ->assertJsonPath('yil.nisab', 505134)->assertJsonCount(2, 'yil.kalemler');
        $id = ZekatYili::value('id');

        // Fiyat ve hesap sonradan değişse de kayıt değişmez
        Fiyat::where('varlik_id', $this->gram->id)->update(['fiyat' => 9000]);
        Islem::query()->delete();
        $this->hesap->delete();
        $y = $this->getJson("/api/v1/aile/zekat/yillar/{$id}")->assertOk()
            ->assertJsonPath('yil.brut', 663000)->assertJsonPath('yil.nisab', 505134)->assertJsonPath('yil.nisab_birim_fiyat', 6300)
            ->assertJsonCount(2, 'yil.kalemler')->assertJsonPath('yil.kalemler.0.hesap_ad', 'Yatırım');
        $this->assertNull($y->json('yil.kalemler.0.hesap_id'));

        // Aynı gün ikinci kayıt reddedilir
        $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => '2026-11-15'])->assertStatus(422)->assertJsonValidationErrors('zekat_gunu');
        $this->assertDatabaseCount('zekat_yillari', 1);

        // Taslak notu değişir, kesinleşince salt okunur ve silinemez
        $this->putJson("/api/v1/aile/zekat/yillar/{$id}", ['not' => 'düzeltildi'])->assertOk()->assertJsonPath('yil.not', 'düzeltildi');
        $this->postJson("/api/v1/aile/zekat/yillar/{$id}/kesinlestir")->assertOk()->assertJsonPath('yil.durum', 'kesinlesti');
        $this->postJson("/api/v1/aile/zekat/yillar/{$id}/kesinlestir")->assertStatus(422);
        $this->putJson("/api/v1/aile/zekat/yillar/{$id}", ['not' => 'x'])->assertStatus(422);
        $this->deleteJson("/api/v1/aile/zekat/yillar/{$id}")->assertStatus(422);
        $this->assertDatabaseCount('zekat_yillari', 1);
    }

    public function test_taslak_silinir_ve_kayit_sonraki_zekat_gunune_tasir(): void
    {
        $this->nakit(600000);
        $this->ayar(['zekat_gunu_turu' => 'miladi', 'miladi_ay' => 11, 'miladi_gun' => 20]);
        $this->getJson('/api/v1/aile/zekat/ayar')->assertJsonPath('zekat_gunu.tarih', '2026-11-20')->assertJsonPath('zekat_gunu.tahmini', false);

        $id = $this->postJson('/api/v1/aile/zekat/yillar', [])->assertCreated()->assertJsonPath('yil.zekat_gunu', '2026-11-20')->json('yil.id');
        $this->getJson('/api/v1/aile/zekat/ayar')->assertJsonPath('zekat_gunu.tarih', '2027-11-20');

        $this->deleteJson("/api/v1/aile/zekat/yillar/{$id}")->assertOk();
        $this->getJson('/api/v1/aile/zekat/ayar')->assertJsonPath('zekat_gunu.tarih', '2026-11-20');
    }

    public function test_odeme_kismi_kalan_ileri_tarih_ve_taslaga_baglama_reddedilir(): void
    {
        $this->nakit(600000);
        $id = $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => '2026-11-15'])->assertCreated()->json('yil.id'); // zekât 15.000

        // Taslağa ödeme bağlanmaz
        $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $id, 'tarih' => '2026-11-15', 'tutar' => 1000])->assertStatus(422)->assertJsonValidationErrors('zekat_yili_id');
        $this->postJson("/api/v1/aile/zekat/yillar/{$id}/kesinlestir")->assertOk();

        $odeme = $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $id, 'tarih' => '2026-11-14', 'tutar' => 6000.25, 'alici' => 'Yardım derneği', 'not' => 'ilk taksit'])
            ->assertCreated()->assertJsonPath('odeme.tutar', 6000.25)->assertJsonPath('odeme.alici', 'Yardım derneği')->json('odeme.id');
        $this->getJson("/api/v1/aile/zekat/yillar/{$id}")->assertJsonPath('yil.zekat', 15000)->assertJsonPath('yil.odenen', 6000.25)->assertJsonPath('yil.kalan', 8999.75)
            ->assertJsonCount(1, 'yil.odemeler');
        $this->getJson('/api/v1/aile/zekat/yillar')->assertJsonPath('yillar.0.kalan', 8999.75);

        $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $id, 'tarih' => '2026-11-16', 'tutar' => 10])->assertStatus(422)->assertJsonValidationErrors('tarih');
        $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $id, 'tarih' => '2026-11-15', 'tutar' => 0])->assertStatus(422);
        $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $id, 'tarih' => '2026-11-15', 'tutar' => 1.234])->assertStatus(422);

        $this->putJson("/api/v1/aile/zekat/odemeler/{$odeme}", ['zekat_yili_id' => $id, 'tarih' => '2026-11-14', 'tutar' => 15000])->assertOk();
        $this->getJson("/api/v1/aile/zekat/yillar/{$id}")->assertJsonPath('yil.kalan', 0);
        $this->deleteJson("/api/v1/aile/zekat/odemeler/{$odeme}")->assertOk();
        $this->getJson("/api/v1/aile/zekat/yillar/{$id}")->assertJsonPath('yil.kalan', 15000);

        // Yıla bağlı olmayan ödeme de kaydedilir
        $this->postJson('/api/v1/aile/zekat/odemeler', ['tarih' => '2026-11-01', 'tutar' => 500])->assertCreated()->assertJsonPath('odeme.zekat_yili_id', null);
        $this->getJson('/api/v1/aile/zekat/odemeler')->assertJsonCount(1, 'odemeler');
    }

    public function test_hatirlatici_yaklasiyor_gecti_odenmemis_zekat_ve_butce_bilgi_satiri(): void
    {
        // Ayar yokken uyarı yok
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()->assertJsonPath('uyarilar.zekat', [])->assertJsonPath('zekat.verilen', 0);

        // 16 gün kaldı (eşik 30)
        $this->ayar(['sonraki_zekat_gunu' => '2026-12-01']);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('uyarilar.zekat.0.tur', 'yaklasiyor')->assertJsonPath('uyarilar.zekat.0.gun', 16)
            ->assertJsonCount(1, 'uyarilar.zekat');
        // Eşik altı değilse uyarı yok
        $this->ayar(['hatirlatma_gun' => 10]);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonCount(0, 'uyarilar.zekat');

        // Gün geçti, kayıt yok
        $this->ayar(['sonraki_zekat_gunu' => '2026-11-01']);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('uyarilar.zekat.0.tur', 'gecti');

        // Kayıt + kesinleştirme + kısmi ödeme: ödenmemiş zekât uyarısı; Bütçe kalanı değişmez, verilen yalnızca bilgi
        $this->nakit(600000);
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        Gelir::create([
            'hane_id' => $this->ben->hane_id, 'uye_id' => $uye, 'gelir_turu_id' => GelirTuru::where('hane_id', $this->ben->hane_id)->value('id'),
            'duzen' => 'duzenli', 'tutar' => 50000, 'para_birimi' => 'TRY', 'tl_tutar' => 50000, 'tarih' => '2026-11-02', 'donem' => '2026-11',
        ]);
        $once = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->json();
        $id = $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => '2026-11-01'])->assertCreated()->json('yil.id');
        $this->postJson("/api/v1/aile/zekat/yillar/{$id}/kesinlestir")->assertOk();
        $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $id, 'tarih' => '2026-11-10', 'tutar' => 4000])->assertCreated();
        $this->postJson('/api/v1/aile/zekat/odemeler', ['tarih' => '2026-10-10', 'tutar' => 300])->assertCreated();

        $sonra = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()
            ->assertJsonPath('zekat.verilen', 4000)->assertJsonPath('zekat.adet', 1)
            ->assertJsonPath('uyarilar.zekat.0.tur', 'odenmemis')->assertJsonPath('uyarilar.zekat.0.kalan', 11000)->json();
        $this->assertSame($once['kalan'], $sonra['kalan']);
        $this->assertSame($once['fark'], $sonra['fark']);
        $this->assertSame($once['gider']['toplam'], $sonra['gider']['toplam']);
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('zekat.verilen', 300);
    }

    public function test_baska_hane_ayar_yil_odeme_borc_goremez_degistiremez(): void
    {
        $this->nakit(600000);
        $this->ayar(['oran_binde' => 30]);
        $yil = $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => '2026-11-15'])->assertCreated()->json('yil.id');
        $this->postJson("/api/v1/aile/zekat/yillar/{$yil}/kesinlestir")->assertOk();
        $odeme = $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $yil, 'tarih' => '2026-11-15', 'tutar' => 100])->json('odeme.id');
        $borc = $this->postJson('/api/v1/aile/zekat/borclar', ['ad' => 'Borç', 'tutar' => 100])->json('borc.id');
        $taslak = $this->postJson('/api/v1/aile/zekat/yillar', ['zekat_gunu' => '2027-11-15'])->assertCreated()->json('yil.id');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $yHesap = Hesap::factory()->create(['user_id' => $yabanci->id]);
        $this->actingAs($yabanci);

        // Ayar: kendi (varsayılan) ayarını görür, benimkini değil
        $this->getJson('/api/v1/aile/zekat/ayar')->assertOk()->assertJsonPath('ayar.oran_binde', 25)->assertJsonCount(1, 'hesaplar');
        $this->putJson('/api/v1/aile/zekat/ayar', ['hesaplar' => [['id' => $this->hesap->id, 'zekata_dahil' => false]]])->assertStatus(422);
        $this->assertTrue($this->hesap->fresh()->zekata_dahil);
        $this->putJson('/api/v1/aile/zekat/ayar', ['oran_binde' => 10])->assertOk();
        $this->assertSame(30, ZekatAyari::where('hane_id', $this->ben->hane_id)->value('oran_binde'));

        // Hesap: benim portföyüm hesaba karışmaz
        $this->hesapla()->assertJsonPath('brut', 0)->assertJsonCount(0, 'kalemler');

        // Yıl
        $this->getJson('/api/v1/aile/zekat/yillar')->assertOk()->assertJsonCount(0, 'yillar');
        $this->getJson("/api/v1/aile/zekat/yillar/{$yil}")->assertForbidden();
        $this->putJson("/api/v1/aile/zekat/yillar/{$taslak}", ['not' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/aile/zekat/yillar/{$taslak}/kesinlestir")->assertForbidden();
        $this->deleteJson("/api/v1/aile/zekat/yillar/{$taslak}")->assertForbidden();

        // Ödeme
        $this->getJson('/api/v1/aile/zekat/odemeler')->assertOk()->assertJsonCount(0, 'odemeler');
        $this->postJson('/api/v1/aile/zekat/odemeler', ['zekat_yili_id' => $yil, 'tarih' => '2026-11-15', 'tutar' => 5])->assertStatus(422)->assertJsonValidationErrors('zekat_yili_id');
        $this->putJson("/api/v1/aile/zekat/odemeler/{$odeme}", ['tarih' => '2026-11-15', 'tutar' => 5])->assertForbidden();
        $this->deleteJson("/api/v1/aile/zekat/odemeler/{$odeme}")->assertForbidden();

        // Borç
        $this->getJson('/api/v1/aile/zekat/borclar')->assertOk()->assertJsonCount(0, 'borclar');
        $this->deleteJson("/api/v1/aile/zekat/borclar/{$borc}")->assertForbidden();

        $this->assertDatabaseCount('zekat_odemeleri', 1);
        $this->assertDatabaseCount('zekat_borclari', 1);
        $this->assertNotNull($yHesap);
    }

    public function test_zekat_tablolari_hane_kaydi_sayilir(): void
    {
        $hane = $this->ben->hane;
        $this->assertFalse(app(HaneYonetimi::class)->kayitVarMi($hane));
        $this->postJson('/api/v1/aile/zekat/borclar', ['ad' => 'Borç', 'tutar' => 10])->assertCreated();
        $this->assertTrue(app(HaneYonetimi::class)->kayitVarMi($hane->fresh()));
    }

    public function test_kimlik_dogrulamasiz_erisilemez_ve_dogrulama(): void
    {
        $this->putJson('/api/v1/aile/zekat/ayar', ['oran_binde' => 0])->assertStatus(422);
        $this->putJson('/api/v1/aile/zekat/ayar', ['nisab_olcutu' => 'platin'])->assertStatus(422);
        $this->putJson('/api/v1/aile/zekat/ayar', ['hicri_ay' => 13])->assertStatus(422);
    }
}
