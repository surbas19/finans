<?php

namespace Tests\Feature;

use App\Enums\KartIslemDurumu;
use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrnekPdf;
use Tests\TestCase;

/**
 * PDF ekstre: yükleme doğrulaması, şifreli / metinsiz PDF, tekrar yükleme, inceleme, onay (hepsi ya da hiçbiri),
 * dosya erişimi ve hane izolasyonu. Gerçek banka PDF'i yok: test içinde üretilen PDF.
 */
class EkstreTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Storage::fake('local');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'Bonus'])->json('kart.id');
    }

    /** Ekim ekstresi (kesim 10.11.2026) */
    private function ekimPdf(string $ek = ''): string
    {
        return OrnekPdf::yaz([[
            'GARANTI BBVA KREDI KARTI HESAP OZETI '.$ek,
            [40 => 'Hesap Kesim Tarihi', 200 => '10.11.2026'],
            [40 => 'Son Odeme Tarihi', 200 => '20.11.2026'],
            [40 => 'Donem Borcu', 200 => '3.479,66 TL'],
            [40 => 'Asgari Odeme Tutari', 200 => '695,93 TL'],
            [40 => '15.10.2026', 110 => 'MIGROS KADIKOY ISTANBUL', 450 => '1.234,56'],
            [40 => '16.10.2026', 110 => 'ENERJISA BASKENT EDAS OTOMATIK ODEME', 450 => '845,10'],
            [40 => '20.10.2026', 110 => 'ODEMENIZ ICIN TESEKKURLER', 450 => '-5.000,00'],
            [40 => '22.10.2026', 110 => 'TEKNOSA ISTANBUL 3/6 TAKSIT', 450 => '1.400,00'],
        ], [
            [40 => '25.10.2026', 110 => 'MIGROS KADIKOY ISTANBUL', 450 => '1.234,56'],
        ]]);
    }

    private function yukle(string $icerik, string $ad = 'ekstre.pdf'): TestResponse
    {
        return $this->post('/api/v1/aile/ekstreler', [
            'kart_id' => $this->kart,
            'dosya' => UploadedFile::fake()->createWithContent($ad, $icerik),
        ], ['Accept' => 'application/json']);
    }

    public function test_yukle_incele_onayla(): void
    {
        Log::spy();
        $cevap = $this->yukle($this->ekimPdf())->assertCreated()
            ->assertJsonPath('ozet.satir', 5)->assertJsonPath('ozet.tekrar', 0)
            ->assertJsonPath('ekstre.durum', 'taslak')
            ->assertJsonPath('ekstre.kesim_tarihi', '2026-11-10')
            ->assertJsonPath('ekstre.donem_borcu', 3479.66)
            ->assertJsonPath('ekstre.okunamayan', [])
            ->assertJsonPath('ekstre.alan_kaynaklari.kesim_tarihi', 'pdf');
        $id = $cevap->json('ekstre.id');
        $ekstre = Ekstre::find($id);
        Storage::disk('local')->assertExists($ekstre->dosya_yolu);
        $this->assertStringStartsWith("ekstreler/{$this->ben->hane_id}/", $ekstre->dosya_yolu);

        $goster = $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk();
        $satirlar = collect($goster->json('satirlar'));
        $this->assertSame([1, 1, 1, 1, 2], $satirlar->pluck('sayfa')->all());
        $this->assertSame(['harcama', 'harcama', 'odeme', 'harcama', 'harcama'], $satirlar->pluck('tur')->all());
        $this->assertStringContainsString('MIGROS KADIKOY', $satirlar[0]['ham_metin']);
        $this->assertNotNull($satirlar[0]['kategori_id']); // Market kuralı
        $this->assertSame([3, 6], [$satirlar[3]['taksit_no'], $satirlar[3]['taksit_sayisi']]);

        // PDF yalnızca uçtan, satır içi, önbelleksiz
        $dosya = $this->get("/api/v1/aile/ekstreler/{$id}/dosya")->assertOk();
        $this->assertSame('application/pdf', $dosya->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $dosya->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', $dosya->headers->get('Content-Disposition'));

        // İnceleme: son satırı dışarıda bırak, birinin tutarını düzelt, son ödemeyi kullanıcı düzeltir
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$satirlar[4]['id']}", ['dahil' => false])->assertOk()->assertJsonPath('islem.durum', 'atlandi');
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$satirlar[1]['id']}", ['tutar' => 845.15])->assertOk()->assertJsonPath('islem.tutar', 845.15);
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['son_odeme_tarihi' => '2026-11-21'])->assertOk()
            ->assertJsonPath('ekstre.alan_kaynaklari.son_odeme_tarihi', 'kullanici');

        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()
            ->assertJsonPath('rapor', ['eklenen' => 4, 'atlanan' => 1, 'tekrar' => 0, 'fatura' => 0])
            ->assertJsonPath('ekstre.durum', 'onaylandi');
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertJsonValidationErrors('ekstre');

        // Rapor: Ekim'de market + fatura; taksit Kasım'a (kesim ayı); kart borcu ödemesi gider değil
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gider.toplam', 2079.71);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 1400);
        // Onaylı ekstrenin satırı incelemeden değişmez
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$satirlar[0]['id']}", ['tutar' => 1])->assertJsonValidationErrors('islem');

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    public function test_gecersiz_sifreli_metinsiz_pdf_kayit_birakmaz(): void
    {
        $this->yukle('Bu bir metin dosyasi, PDF degil.', 'sahte.pdf')->assertJsonValidationErrors('dosya');
        $this->post('/api/v1/aile/ekstreler', ['kart_id' => $this->kart, 'dosya' => UploadedFile::fake()->create('buyuk.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('dosya');
        $this->yukle(OrnekPdf::yaz([['GIZLI EKSTRE 10.11.2026 MARKET 100,00']], sifreli: true))
            ->assertJsonValidationErrors('dosya')->assertJsonFragment(['PDF şifreli. Bankanın şifreli ekstresini açıp şifresiz olarak kaydedin (ör. "PDF olarak yazdır") ve onu yükleyin.']);
        $metinsiz = $this->yukle(OrnekPdf::metinsiz())->assertJsonValidationErrors('dosya');
        $this->assertStringContainsString('OCR desteklenmiyor', $metinsiz->json('errors.dosya.0'));

        $this->assertSame(0, Ekstre::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_tekrar_yukleme_ve_tekrar_satir(): void
    {
        $ilk = $this->yukle($this->ekimPdf())->assertCreated()->json('ekstre.id');
        $this->postJson("/api/v1/aile/ekstreler/{$ilk}/onayla")->assertOk();

        // Aynı dosya: reddedilir, nedeni açıklanır
        $tekrar = $this->yukle($this->ekimPdf(), 'yeniden-indirilen.pdf')->assertJsonValidationErrors('dosya');
        $this->assertStringContainsString('13.11.2026 tarihinde yüklendi (Bonus, Kasım 2026 ekstresi)', $tekrar->json('errors.dosya.0'));

        // Bayt olarak farklı ama aynı dönem: satırların hepsi "zaten var", aynı kesim tarihi onaylanamaz
        $ikinci = $this->yukle($this->ekimPdf('(kopya)'))->assertCreated()->assertJsonPath('ozet.tekrar', 5)->json('ekstre.id');
        $goster = $this->getJson("/api/v1/aile/ekstreler/{$ikinci}")->assertOk();
        $this->assertSame(['atlandi'], collect($goster->json('satirlar'))->pluck('durum')->unique()->values()->all());
        $this->assertSame('Kasım 2026 ekstresi', $goster->json('satirlar.0.tekrar.yer'));
        $this->postJson("/api/v1/aile/ekstreler/{$ikinci}/onayla")->assertJsonValidationErrors('kesim_tarihi');
        $this->assertSame(5, KartIslemi::where('durum', KartIslemDurumu::Onayli->value)->count());
    }

    public function test_onay_hepsi_ya_da_hicbiri(): void
    {
        $ilk = $this->yukle($this->ekimPdf())->json('ekstre.id');
        $this->postJson("/api/v1/aile/ekstreler/{$ilk}/onayla")->assertOk();

        // Sonraki dönem ekstresi Ekim'deki bir satırı da içeriyor (çakışan dönem)
        $kasim = $this->yukle(OrnekPdf::yaz([[
            [40 => 'Hesap Kesim Tarihi', 200 => '10.12.2026'],
            [40 => '25.10.2026', 110 => 'MIGROS KADIKOY ISTANBUL', 450 => '1.234,56'],
            [40 => '12.11.2026', 110 => 'SHELL ATASEHIR', 450 => '900,00'],
            [40 => '13.11.2026', 110 => 'OKUNAMAYAN TUTAR SATIRI', 450 => '0,00'],
        ]]))->assertCreated()->assertJsonPath('ozet.tekrar', 1)->assertJsonPath('ekstre.okunamayan', ['son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'])
            ->json('ekstre.id');
        $satirlar = collect($this->getJson("/api/v1/aile/ekstreler/{$kasim}")->json('satirlar'));
        $this->assertSame(['tutar'], $satirlar[2]['okuma']['alanlar']);

        // Okunamayan tutar düzeltilmeden onay yok; hiçbir satır yazılmaz
        $this->postJson("/api/v1/aile/ekstreler/{$kasim}/onayla")->assertJsonValidationErrors('islem');
        $this->assertSame('taslak', Ekstre::find($kasim)->durum->value);
        $this->assertSame(0, KartIslemi::where('ekstre_id', $kasim)->where('durum', 'onayli')->count());

        // Tekrar satırı geri alınırsa onay yine reddedilir (aynı işlem iki kez sayılmaz)
        $this->putJson("/api/v1/aile/ekstreler/{$kasim}/satirlar/{$satirlar[2]['id']}", ['tutar' => 45])->assertOk()
            ->assertJsonPath('islem.okuma.alanlar', []);
        $this->putJson("/api/v1/aile/ekstreler/{$kasim}/satirlar/{$satirlar[0]['id']}", ['dahil' => true])->assertOk();
        $this->postJson("/api/v1/aile/ekstreler/{$kasim}/onayla")->assertJsonValidationErrors('islem');
        $this->assertSame(0, KartIslemi::where('ekstre_id', $kasim)->where('durum', 'onayli')->count());

        $this->putJson("/api/v1/aile/ekstreler/{$kasim}/satirlar/{$satirlar[0]['id']}", ['dahil' => false])->assertOk();
        $this->postJson("/api/v1/aile/ekstreler/{$kasim}/onayla")->assertOk()->assertJsonPath('rapor', ['eklenen' => 2, 'atlanan' => 1, 'tekrar' => 1, 'fatura' => 0]);
        $this->assertSame(1, KartIslemi::where('aciklama', 'MIGROS KADIKOY ISTANBUL')->where('islem_tarihi', '2026-10-25')->where('durum', 'onayli')->count());

        // Silme: satırlar ve dosya gider
        $yol = Ekstre::find($kasim)->dosya_yolu;
        $this->deleteJson("/api/v1/aile/ekstreler/{$kasim}")->assertOk();
        Storage::disk('local')->assertMissing($yol);
        $this->assertSame(0, KartIslemi::where('ekstre_id', $kasim)->count());
    }

    public function test_kesim_tarihi_okunamazsa_kullanici_girer(): void
    {
        $id = $this->yukle(OrnekPdf::yaz([[
            [40 => '15.10.2026', 110 => 'MIGROS', 450 => '100,00'],
        ]]))->assertCreated()->assertJsonPath('ekstre.okunamayan', ['kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'])->json('ekstre.id');
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertJsonValidationErrors('kesim_tarihi');
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['kesim_tarihi' => '2026-11-10'])->assertOk()
            ->assertJsonPath('ekstre.alan_kaynaklari.kesim_tarihi', 'kullanici')
            ->assertJsonPath('ekstre.okunamayan', ['son_odeme_tarihi', 'donem_borcu', 'asgari_odeme']);
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk();
    }

    public function test_asgari_odeme_okunamazsa_borc_carpi_kart_orani(): void
    {
        // Asgari ödeme PDF'te yok; borç 3.479,66 → varsayılan %40 = 1.391,86 (kuruşa yarıdan yukarı)
        $pdf = fn (string $ek): string => OrnekPdf::yaz([[
            'ORNEK BANKA EKSTRE '.$ek,
            [40 => 'Hesap Kesim Tarihi', 200 => '10.11.2026'],
            [40 => 'Son Odeme Tarihi', 200 => '20.11.2026'],
            [40 => 'Donem Borcu', 200 => '3.479,66 TL'],
            [40 => '15.10.2026', 110 => 'MIGROS KADIKOY', 450 => '100,00'],
        ]]);
        $id = $this->yukle($pdf('a'))->assertCreated()
            ->assertJsonPath('ekstre.asgari_odeme', 1391.86)
            ->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'hesaplandi')
            ->assertJsonPath('ekstre.okunamayan', [])->json('ekstre.id');

        // Dönem borcu düzeltilince hesaplanan asgari yenilenir
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['donem_borcu' => 1000])->assertOk()
            ->assertJsonPath('ekstre.asgari_odeme', 400)->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'hesaplandi');
        // Kullanıcı elle girerse dokunulmaz (borç değişse de)
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['asgari_odeme' => 450])->assertOk()
            ->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'kullanici');
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['donem_borcu' => 2000])->assertOk()
            ->assertJsonPath('ekstre.asgari_odeme', 450)->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'kullanici');

        // Kartın oranı %30: yeni ekstre ona göre; oran 1–100 dışı reddedilir
        $this->putJson("/api/v1/aile/kartlar/{$this->kart}", ['banka_id' => Kart::find($this->kart)->banka_id, 'sahip_uye_id' => Kart::find($this->kart)->sahip_uye_id, 'ad' => 'Bonus', 'asgari_oran' => 101])
            ->assertJsonValidationErrors('asgari_oran');
        $this->putJson("/api/v1/aile/kartlar/{$this->kart}", ['banka_id' => Kart::find($this->kart)->banka_id, 'sahip_uye_id' => Kart::find($this->kart)->sahip_uye_id, 'ad' => 'Bonus', 'asgari_oran' => 30])
            ->assertOk()->assertJsonPath('kart.asgari_oran', 30);
        $this->yukle($pdf('b'))->assertCreated()->assertJsonPath('ekstre.asgari_odeme', 1043.90);

        // PDF'te asgari yazıyorsa o kullanılır
        $this->yukle($this->ekimPdf('c'))->assertJsonPath('ekstre.asgari_odeme', 695.93)->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'pdf');
    }

    public function test_yapi_kredi_duzeni_banka_tanima_mutabakat_ve_kalan_taksit(): void
    {
        // Yapı Kredi düzeni (uydurma veri): ay adlı tarih, "+" alacak, sağda puan, "kalan / N" ve alt satırda taksit bilgisi
        $pdf = OrnekPdf::yaz([[
            'YAPI VE KREDI BANKASI A.S. WORLDPUAN',
            [40 => 'Hesap Kesim Tarihi', 200 => ': 13 Ekim 2026'],
            [40 => 'Son Odeme Tarihi', 200 => ': 23 Ekim 2026'],
            [40 => 'Donem Borcu', 200 => ': 1.000,00 TL'],
            [40 => 'Islem Tarihi Islemler', 450 => 'Tutar(TL)', 520 => 'Kalan Tutar/Taksit'],
            [40 => 'ONCEKI DONEM HESAP OZETI BORCU', 450 => '500,00'],
            [40 => '21 Eylul 2026 ODEME-INTERNET BANKACILIGI', 450 => '+500,00'],
            [40 => '28 Eylul 2026 MARKET ISTANBUL TR', 450 => '400,00', 540 => '4'],
            [40 => '11 Agustos 2026 HEPSIPAY MAGAZA TR', 450 => '600,00', 520 => '3.600,00 / 6'],
            [40 => "6.000,00 TL'lik islemin 3 / 9 taksidi"],
            [40 => 'TOPLAM', 450 => '1.000,00', 540 => '3'],
        ]]);

        $cevap = $this->yukle($pdf)->assertCreated()
            ->assertJsonPath('ozet.satir', 3)
            ->assertJsonPath('ekstre.banka_profili', 'yapikredi')
            ->assertJsonPath('ekstre.kesim_tarihi', '2026-10-13')
            ->assertJsonPath('ekstre.denetim.durum', 'tutuyor')
            ->assertJsonPath('ekstre.kalan_taksit', 3600)
            ->assertJsonPath('ekstre.asgari_odeme', 400);
        $satirlar = collect($this->getJson('/api/v1/aile/ekstreler/'.$cevap->json('ekstre.id'))->json('satirlar'));
        $this->assertSame(['odeme', 'harcama', 'harcama'], $satirlar->pluck('tur')->all());
        $this->assertSame(['2026-09-21', '2026-09-28', '2026-08-11'], $satirlar->pluck('islem_tarihi')->all());
        $this->assertSame([3, 9, 6000, 3600], [$satirlar[2]['taksit_no'], $satirlar[2]['taksit_sayisi'], $satirlar[2]['taksit_toplam'], $satirlar[2]['taksit_kalan']]);
        $this->assertSame('MARKET ISTANBUL TR', $satirlar[1]['aciklama']);

        // Aynı ekstre, borcu tutmayan: uyuşmazlık engellemez ama farkıyla görünür
        $bozuk = str_replace('1.000,00 TL', '1.100,00 TL', $pdf);
        $this->yukle($bozuk, 'bozuk.pdf')->assertCreated()
            ->assertJsonPath('ekstre.denetim.durum', 'tutmuyor')->assertJsonPath('ekstre.denetim.fark', '-100.00');
    }

    public function test_baska_hane_ekstreye_ve_pdfe_erisemez(): void
    {
        $id = $this->yukle($this->ekimPdf())->json('ekstre.id');
        $satir = KartIslemi::where('ekstre_id', $id)->first();

        $yabanci = User::factory()->create();
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/ekstreler')->assertOk()->assertJsonCount(0, 'ekstreler');
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertForbidden();
        $this->get("/api/v1/aile/ekstreler/{$id}/dosya")->assertForbidden();
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['odendi' => true])->assertForbidden();
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$satir->id}", ['dahil' => false])->assertForbidden();
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertForbidden();
        $this->deleteJson("/api/v1/aile/ekstreler/{$id}")->assertForbidden();
        $this->post('/api/v1/aile/ekstreler', ['kart_id' => $this->kart, 'dosya' => UploadedFile::fake()->createWithContent('e.pdf', $this->ekimPdf('x'))], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('kart_id');
        $this->assertSame(1, Ekstre::count());
    }
}
