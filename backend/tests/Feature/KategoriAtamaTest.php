<?php

namespace Tests\Feature;

use App\Enums\EkstreDurumu;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Ekstre;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\HarcamaKategorisi;
use App\Models\KartIslemi;
use App\Models\KategoriKurali;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\Aile\KategoriBulucu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * İşleme kategori atama (uzun basma): aynı açıklamalı bütün satırlar aynı kategoriye geçer (mevcut kategori ne olursa
 * olsun), açıklama kural olur; güncel ekstre dönemi görünümü; kategori rengi; "Ek ders" gelir türü; hane izolasyonu.
 */
class KategoriAtamaTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $uye;

    private int $kartA;

    private int $kartB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $this->uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->kartA = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $this->uye, 'ad' => 'Bonus'])->json('kart.id');
        $this->kartB = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $this->uye, 'ad' => 'Maximum'])->json('kart.id');
    }

    private function kategori(string $ad): int
    {
        return (int) HarcamaKategorisi::where('hane_id', $this->ben->hane_id)->where('ad', $ad)->value('id');
    }

    private function satir(int $kart, string $aciklama, string $tarih, ?Ekstre $ekstre = null, KartIslemDurumu $durum = KartIslemDurumu::Onayli): KartIslemi
    {
        return KartIslemi::create([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $kart, 'ekstre_id' => $ekstre?->id, 'durum' => $durum, 'islem_tarihi' => $tarih,
            'aciklama' => $aciklama, 'tutar' => '100.00', 'tur' => KartIslemTuru::Harcama, 'rapor_donemi' => substr($tarih, 0, 7), 'parmak_izi' => md5($kart.$aciklama.$tarih.microtime()),
        ]);
    }

    private function ekstre(int $kart, string $kesim, EkstreDurumu $durum = EkstreDurumu::Onaylandi): Ekstre
    {
        return Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $kart, 'durum' => $durum, 'kesim_tarihi' => $kesim, 'donem_borcu' => '1000.00']);
    }

    public function test_atama_ayni_aciklamali_butun_satirlari_ve_kural_kaydeder(): void
    {
        $market = $this->kategori('Market');
        $giyim = $this->kategori('Giyim');
        $a = $this->satir($this->kartA, 'Kahve Dunyasi Kadikoy', '2026-11-03');
        $b = $this->satir($this->kartB, 'KAHVE DÜNYASI  KADIKÖY', '2026-10-05');            // harf / boşluk / aksan farkı: aynı açıklama
        $taslak = $this->satir($this->kartA, 'kahve dunyasi kadikoy', '2026-09-01', null, KartIslemDurumu::Taslak);
        $baska = $this->satir($this->kartA, 'KAHVE DUNYASI ATASEHIR', '2026-11-04');
        $b->update(['kategori_id' => $giyim]);                                                // mevcut başka kategori ezilir

        $r = $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $market])->assertOk()
            ->assertJsonPath('guncellenen', 3)->assertJsonPath('kategori_ad', 'Market');
        $this->assertStringContainsString('3 işlem "Market" olarak işaretlendi', $r->json('message'));
        $this->assertSame($market, $a->fresh()->kategori_id);
        $this->assertSame($market, $b->fresh()->kategori_id);
        $this->assertSame($market, $taslak->fresh()->kategori_id);
        $this->assertNull($baska->fresh()->kategori_id);                                      // farklı açıklama dokunulmaz
        $kural = KategoriKurali::where('hane_id', $this->ben->hane_id)->where('desen', 'KAHVE DUNYASI KADIKOY')->first();
        $this->assertSame($market, $kural->kategori_id);
        // Sonraki ekstrelerde aynı açıklama otomatik bu kategoriye girer
        $this->assertSame($market, app(KategoriBulucu::class)->bul((int) $this->ben->hane_id, 'KAHVE DUNYASI KADIKOY 123'));

        // Başka kategoriye çevirince kural taşınır, tüm satırlar yeni kategoriye geçer
        $this->putJson("/api/v1/aile/kart-islemleri/{$b->id}/kategori", ['kategori_id' => $giyim])->assertOk()->assertJsonPath('guncellenen', 3);
        $this->assertSame(1, KategoriKurali::where('hane_id', $this->ben->hane_id)->where('desen', 'KAHVE DUNYASI KADIKOY')->count());
        $this->assertSame($giyim, KategoriKurali::where('desen', 'KAHVE DUNYASI KADIKOY')->value('kategori_id'));
        $this->assertSame($giyim, $a->fresh()->kategori_id);

        // Kategorisiz yap: kural kalkar, satırlar kategorisiz
        $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => null])->assertOk()->assertJsonPath('kategori_ad', null);
        $this->assertNull($b->fresh()->kategori_id);
        $this->assertSame(0, KategoriKurali::where('desen', 'KAHVE DUNYASI KADIKOY')->count());
        // Tutar / rapor dönemi etkilenmez
        $this->assertSame('100.00', $a->fresh()->tutar);
        $this->assertSame('2026-11', $a->fresh()->rapor_donemi);
    }

    public function test_kategori_listesi_kullanim_sayisini_verir(): void
    {
        $market = $this->kategori('Market');
        $giyim = $this->kategori('Giyim');
        foreach (['A1', 'A2', 'A3'] as $a) {
            $this->satir($this->kartA, $a, '2026-11-03')->update(['kategori_id' => $market]);
        }
        $this->satir($this->kartA, 'B1', '2026-11-04')->update(['kategori_id' => $giyim]);
        $this->satir($this->kartA, 'T1', '2026-11-05', null, KartIslemDurumu::Taslak)->update(['kategori_id' => $giyim]);   // taslak sayılmaz
        $this->satir($this->kartA, 'K1', '2026-11-06');                                                                      // kategorisiz sayılmaz

        $kat = collect($this->getJson('/api/v1/aile/kategoriler')->assertOk()->json('kategoriler'))->keyBy('ad');
        $this->assertSame(3, $kat['Market']['kullanim']);
        $this->assertSame(1, $kat['Giyim']['kullanim']);
        $this->assertSame(0, $kat['Eğitim']['kullanim']);

        // Başka hane kendi sayısını görür (bu hanenin kullanımı sızmaz)
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->assertSame(0, collect($this->getJson('/api/v1/aile/kategoriler')->json('kategoriler'))->sum('kullanim'));
    }

    public function test_uzun_aciklama_kelime_sinirinda_kisaltilir_ve_kisa_aciklamada_kural_olmaz(): void
    {
        $market = $this->kategori('Market');
        $uzun = str_repeat('BUYUK ', 15).'SON';
        $a = $this->satir($this->kartA, $uzun, '2026-11-03');
        $b = $this->satir($this->kartB, $uzun, '2026-10-03');
        $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $market])->assertOk()->assertJsonPath('guncellenen', 2);
        $desen = KategoriKurali::where('hane_id', $this->ben->hane_id)->where('kategori_id', $market)->whereRaw('LENGTH(desen) > 20')->value('desen');
        $this->assertLessThanOrEqual(60, mb_strlen($desen));
        $this->assertStringEndsWith('BUYUK', $desen);                                         // kelime ortasında kesilmez
        $this->assertSame($market, $b->fresh()->kategori_id);

        $tek = $this->satir($this->kartA, 'X', '2026-11-05');
        $sayi = KategoriKurali::count();
        $this->putJson("/api/v1/aile/kart-islemleri/{$tek->id}/kategori", ['kategori_id' => $market])->assertOk()->assertJsonPath('guncellenen', 1);
        $this->assertSame($sayi, KategoriKurali::count());
    }

    public function test_guncel_donem_en_yeni_onayli_ekstre_ve_ekstreye_girmemis_elle_satirlar(): void
    {
        $eski = $this->ekstre($this->kartA, '2026-09-10');
        $yeni = $this->ekstre($this->kartA, '2026-10-10');
        $this->ekstre($this->kartA, '2026-11-10', EkstreDurumu::Taslak);                       // taslak ekstre güncel sayılmaz
        $eskiSatir = $this->satir($this->kartA, 'ESKI', '2026-09-05', $eski);
        $yeniSatir = $this->satir($this->kartA, 'YENI', '2026-10-05', $yeni);
        $elleAcik = $this->satir($this->kartA, 'ELLE ACIK', '2026-11-02');                      // son kesimden sonra, ekstresiz
        $elleEski = $this->satir($this->kartA, 'ELLE ESKI', '2026-10-02');                      // son kesimden önce, ekstresiz
        $bEksiz = $this->satir($this->kartB, 'B ELLE', '2026-08-01');                           // ekstresi hiç olmayan kart: hepsi

        $r = $this->getJson('/api/v1/aile/kart-islemleri?guncel=1')->assertOk();
        $ac = collect($r->json('islemler'))->pluck('aciklama')->sort()->values()->all();
        $this->assertSame(['B ELLE', 'ELLE ACIK', 'YENI'], $ac);
        $kartlar = collect($r->json('toplam.kartlar'));
        $this->assertSame(2, $kartlar->firstWhere('kart_id', $this->kartA)['adet']);
        $this->assertEquals(200, $kartlar->firstWhere('kart_id', $this->kartA)['gider']);
        $this->assertSame('Ekim 2026', $kartlar->firstWhere('kart_id', $this->kartA)['ekstre_donemi']);
        $this->assertSame(1, $kartlar->firstWhere('kart_id', $this->kartB)['adet']);
        // Süzgeç (kart) güncel görünümle birleşir
        $this->getJson("/api/v1/aile/kart-islemleri?guncel=1&kart_id={$this->kartB}")->assertJsonCount(1, 'islemler');
        // Arama / tarih verilince tüm dönemler
        $this->getJson('/api/v1/aile/kart-islemleri?guncel=1&ara=ESKI')->assertJsonCount(2, 'islemler');
        $this->getJson('/api/v1/aile/kart-islemleri?guncel=1&tarih_bas=2026-01-01')->assertJsonCount(5, 'islemler');
        // guncel olmadan eskisi gibi (hepsi)
        $this->getJson('/api/v1/aile/kart-islemleri')->assertJsonCount(5, 'islemler');
        $this->assertNotNull($eskiSatir->id.$elleEski->id.$bEksiz->id);
    }

    public function test_yeni_kategori_hanede_kullanilmayan_renk_alir_ve_ek_ders_maastan_sonra(): void
    {
        $kullanilan = HarcamaKategorisi::where('hane_id', $this->ben->hane_id)->pluck('renk')->all();
        $ilk = $this->postJson('/api/v1/aile/kategoriler', ['ad' => 'Tatil'])->assertCreated()->json('kategori');
        $this->assertNotContains($ilk['renk'], $kullanilan);
        $ikinci = $this->postJson('/api/v1/aile/kategoriler', ['ad' => 'Hobi'])->assertCreated()->json('kategori');
        $this->assertNotSame($ilk['renk'], $ikinci['renk']);
        // Renk verilirse o kullanılır
        $this->postJson('/api/v1/aile/kategoriler', ['ad' => 'Kedi', 'renk' => 'red'])->assertCreated()->assertJsonPath('kategori.renk', 'red');

        $turler = GelirTuru::where('hane_id', $this->ben->hane_id)->orderBy('sira')->pluck('ad')->all();
        $this->assertSame(['Maaş', 'Ek ders', 'Kira geliri'], array_slice($turler, 0, 3));
    }

    public function test_ek_ders_migration_mevcut_hanelere_maastan_sonra_ekler_varsa_dokunmaz(): void
    {
        // Eski hane: "Ek ders" olmayan sıra (Maaş 1, Kira 2, Diğer 3)
        $eski = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($eski);
        GelirTuru::where('hane_id', $eski->hane_id)->where('ad', 'Ek ders')->delete();
        GelirTuru::where('hane_id', $eski->hane_id)->orderBy('sira')->get()->each(fn (GelirTuru $t, int $i) => $t->update(['sira' => $i + 1]));
        // Ek dersi kendisi eklemiş hane: dokunulmaz
        $kendi = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($kendi);
        $mevcut = GelirTuru::where('hane_id', $kendi->hane_id)->where('ad', 'Ek ders')->first();
        $sira = $mevcut->sira;

        (include database_path('migrations/2026_11_19_000100_gelir_turu_ek_ders_ekle.php'))->up();

        $adlar = GelirTuru::where('hane_id', $eski->hane_id)->orderBy('sira')->pluck('ad')->all();
        $this->assertSame(['Maaş', 'Ek ders', 'Kira geliri'], array_slice($adlar, 0, 3));
        $this->assertSame(9, count($adlar));
        $siralar = GelirTuru::where('hane_id', $eski->hane_id)->pluck('sira')->all();
        $this->assertSame(count($siralar), count(array_unique($siralar)));                    // sıralar çakışmaz
        $this->assertSame(1, GelirTuru::where('hane_id', $kendi->hane_id)->where('ad', 'Ek ders')->count());
        $this->assertSame($sira, $mevcut->fresh()->sira);
        // İkinci çalıştırma yinelemez
        (include database_path('migrations/2026_11_19_000100_gelir_turu_ek_ders_ekle.php'))->up();
        $this->assertSame(1, GelirTuru::where('hane_id', $eski->hane_id)->where('ad', 'Ek ders')->count());
        $this->assertGreaterThan(0, DB::table('gelir_turleri')->count());
    }

    public function test_baska_hane_islemi_ve_kategoriyi_goremez_degistiremez(): void
    {
        $market = $this->kategori('Market');
        $satir = $this->satir($this->kartA, 'SECRET SHOP', '2026-11-03');
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $yabanciKategori = (int) HarcamaKategorisi::where('hane_id', $yabanci->hane_id)->where('ad', 'Market')->value('id');
        $yabanciSatir = KartIslemi::create([
            'hane_id' => $yabanci->hane_id, 'kart_id' => $this->kartA, 'durum' => KartIslemDurumu::Onayli, 'islem_tarihi' => '2026-11-03',
            'aciklama' => 'SECRET SHOP', 'tutar' => '5.00', 'tur' => KartIslemTuru::Harcama, 'rapor_donemi' => '2026-11', 'parmak_izi' => 'x1',
        ]);

        $this->actingAs($yabanci);
        // Başkasının işlemine kategori atayamaz
        $this->putJson("/api/v1/aile/kart-islemleri/{$satir->id}/kategori", ['kategori_id' => $yabanciKategori])->assertForbidden();
        // Kendi işlemine başkasının kategorisini atayamaz
        $this->putJson("/api/v1/aile/kart-islemleri/{$yabanciSatir->id}/kategori", ['kategori_id' => $market])->assertJsonValidationErrors('kategori_id');
        // Kendi işlemini kategilerken diğer hanenin aynı açıklamalı satırı değişmez
        $this->putJson("/api/v1/aile/kart-islemleri/{$yabanciSatir->id}/kategori", ['kategori_id' => $yabanciKategori])->assertOk()->assertJsonPath('guncellenen', 1);
        $this->assertNull($satir->fresh()->kategori_id);
        $this->assertSame(0, KategoriKurali::where('hane_id', $this->ben->hane_id)->where('desen', 'SECRET SHOP')->count());
        $this->getJson('/api/v1/aile/kart-islemleri?guncel=1')->assertOk()->assertJsonCount(0, 'islemler');
    }

    public function test_yalniz_bu_islem_kapsami_kurala_ve_digerlerine_dokunmaz(): void
    {
        $market = $this->kategori('Market');
        $giyim = $this->kategori('Giyim');
        $a = $this->satir($this->kartA, 'KAHVE DUNYASI KADIKOY', '2026-11-03');
        $b = $this->satir($this->kartB, 'KAHVE DUNYASI KADIKOY', '2026-10-05');
        $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $market])->assertOk()->assertJsonPath('guncellenen', 2);

        $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $giyim, 'kapsam' => 'tek'])->assertOk()
            ->assertJsonPath('guncellenen', 1)->assertJsonPath('geri_al.kural', null);
        $this->assertSame($giyim, $a->fresh()->kategori_id);
        $this->assertSame($market, $b->fresh()->kategori_id);
        $this->assertSame($market, KategoriKurali::where('desen', 'KAHVE DUNYASI KADIKOY')->value('kategori_id'));
        $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $giyim, 'kapsam' => 'bazi'])->assertStatus(422);
    }

    public function test_geri_al_satirlari_ve_kurali_onceki_haline_dondurur(): void
    {
        $market = $this->kategori('Market');
        $giyim = $this->kategori('Giyim');
        $a = $this->satir($this->kartA, 'KAHVE DUNYASI KADIKOY', '2026-11-03');
        $b = $this->satir($this->kartB, 'KAHVE DUNYASI KADIKOY', '2026-10-05');
        $c = $this->satir($this->kartB, 'KAHVE DUNYASI KADIKOY', '2026-09-05');
        $b->update(['kategori_id' => $giyim]);

        // Kural yokken atama → geri al: kural silinir, satırlar eski kategorilerine (a, c kategorisiz; b Giyim)
        $geri = $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $market])->assertOk()
            ->assertJsonPath('geri_al.kural.var', false)->json('geri_al');
        $this->postJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori-geri-al", $geri)->assertOk()->assertJsonPath('message', 'Geri alındı.');
        $this->assertNull($a->fresh()->kategori_id);
        $this->assertSame($giyim, $b->fresh()->kategori_id);
        $this->assertNull($c->fresh()->kategori_id);
        $this->assertSame(0, KategoriKurali::where('desen', 'KAHVE DUNYASI KADIKOY')->count());

        // Kural varken başka kategoriye çevirme → geri al: kural eski kategorisine döner
        $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => $market])->assertOk();
        $geri = $this->putJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori", ['kategori_id' => null])->assertOk()->json('geri_al');
        $this->assertSame(0, KategoriKurali::where('desen', 'KAHVE DUNYASI KADIKOY')->count());
        $this->postJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori-geri-al", $geri)->assertOk();
        $this->assertSame($market, KategoriKurali::where('desen', 'KAHVE DUNYASI KADIKOY')->value('kategori_id'));
        $this->assertSame($market, $c->fresh()->kategori_id);
    }

    public function test_geri_al_baska_hanenin_satirini_ve_kategorisini_kabul_etmez(): void
    {
        $a = $this->satir($this->kartA, 'KAHVE DUNYASI KADIKOY', '2026-11-03');
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $onunKategorisi = (int) HarcamaKategorisi::where('hane_id', $yabanci->hane_id)->where('ad', 'Market')->value('id');
        $onunSatiri = KartIslemi::create([
            'hane_id' => $yabanci->hane_id, 'kart_id' => $this->kartA, 'durum' => KartIslemDurumu::Onayli, 'islem_tarihi' => '2026-11-03',
            'aciklama' => 'X', 'tutar' => '1.00', 'tur' => KartIslemTuru::Harcama, 'rapor_donemi' => '2026-11', 'parmak_izi' => md5('yabanci'),
        ]);

        $this->postJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori-geri-al", ['gruplar' => [['kategori_id' => null, 'idler' => [$onunSatiri->id]]], 'kural' => null])
            ->assertStatus(422);
        $this->postJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori-geri-al", ['gruplar' => [['kategori_id' => $onunKategorisi, 'idler' => [$a->id]]], 'kural' => null])
            ->assertStatus(422);
        // Başka hane bu hanenin satırı üzerinden geri alamaz, öneri de göremez
        $this->actingAs($yabanci)->postJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori-geri-al", ['gruplar' => [], 'kural' => null])->assertForbidden();
        $this->getJson("/api/v1/aile/kart-islemleri/{$a->id}/kategori-onerisi")->assertForbidden();
    }

    public function test_oneri_kuraldan_ya_da_ayni_ilk_kelimeli_satirlardan(): void
    {
        $market = $this->kategori('Market');
        $yeme = $this->kategori('Yeme-içme');
        // Varsayılan kural: MIGROS → Market
        $migros = $this->satir($this->kartA, 'MIGROS KADIKOY 2134', '2026-11-03');
        $this->satir($this->kartA, 'MIGROS KADIKOY 2134', '2026-10-03');
        $this->getJson("/api/v1/aile/kart-islemleri/{$migros->id}/kategori-onerisi")->assertOk()
            ->assertJsonPath('ayni_aciklamali', 2)
            ->assertJsonPath('oneri.kategori_id', $market)->assertJsonPath('oneri.gerekce', 'kural');
        // Zaten o kategorideyse öneri yok
        $migros->update(['kategori_id' => $market]);
        $this->getJson("/api/v1/aile/kart-islemleri/{$migros->id}/kategori-onerisi")->assertJsonPath('oneri', null);

        // Kural yok: ilk kelimesi aynı, açıklaması farklı kategorili satırlardan en çok kullanılan
        $this->satir($this->kartA, 'KAHVE DUNYASI KADIKOY', '2026-11-01')->update(['kategori_id' => $yeme]);
        $this->satir($this->kartA, 'KAHVE DUNYASI BESIKTAS', '2026-11-02')->update(['kategori_id' => $yeme]);
        $this->satir($this->kartA, 'KAHVE ATOLYESI', '2026-11-02')->update(['kategori_id' => $market]);
        $yeni = $this->satir($this->kartB, 'KAHVE DUNYASI ATASEHIR', '2026-11-05');
        $this->getJson("/api/v1/aile/kart-islemleri/{$yeni->id}/kategori-onerisi")->assertOk()
            ->assertJsonPath('ayni_aciklamali', 1)
            ->assertJsonPath('oneri.kategori_id', $yeme)->assertJsonPath('oneri.gerekce', 'benzer')->assertJsonPath('oneri.adet', 2);

        // Dayanak yoksa öneri yok (uydurulmaz)
        $bos = $this->satir($this->kartA, 'ZZZ TICARET', '2026-11-05');
        $this->getJson("/api/v1/aile/kart-islemleri/{$bos->id}/kategori-onerisi")->assertJsonPath('oneri', null);
    }
}
