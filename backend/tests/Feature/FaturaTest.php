<?php

namespace Tests\Feature;

use App\Models\Fatura;
use App\Models\FaturaTuru;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrnekPdf;
use Tests\TestCase;

/**
 * Faturalar: ekstreden aday, güven ve gerekçe, belirsiz eşleşme, otomatik kayıt, hizmet dönemi (uydurulmaz),
 * bekleyen faturaya bağlama, bağ çözme, gider iki kez sayılmaz, geçmiş dönem, uyarılar, hane izolasyonu.
 */
class FaturaTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kart;

    /** @var array<string, int> */
    private array $s = [];

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

        $tur = fn (string $ad): int => FaturaTuru::whereNull('hane_id')->where('ad', $ad)->value('id');
        $saglayici = fn (string $ad, string $turAd, array $desenler, bool $otomatik = false): int => $this->postJson('/api/v1/aile/saglayicilar', [
            'ad' => $ad, 'fatura_turu_id' => $tur($turAd), 'desenler' => $desenler, 'otomatik_kayit' => $otomatik,
        ])->assertCreated()->json('saglayici.id');
        $this->s = [
            'iski' => $saglayici('İSKİ', 'Su', ['İSKİ SU'], true),
            'igdas' => $saglayici('İGDAŞ', 'Doğalgaz', [], true),
            'enerjisa' => $saglayici('Enerjisa', 'Elektrik', ['ENERJISA BASKENT']),
            'ayedas' => $saglayici('Ayedaş', 'Elektrik', ['BASKENT EDAS']),
        ];
    }

    private function ekstreYukle(array $satirlar, string $kesim = '10.11.2026'): int
    {
        $pdf = OrnekPdf::yaz([[
            [40 => 'Hesap Kesim Tarihi', 200 => $kesim],
            ...array_map(fn (array $s): array => [40 => $s[0], 110 => $s[1], 450 => $s[2]], $satirlar),
        ]]);

        return $this->post('/api/v1/aile/ekstreler', ['kart_id' => $this->kart, 'dosya' => UploadedFile::fake()->createWithContent('e.pdf', $pdf)], ['Accept' => 'application/json'])
            ->assertCreated()->json('ekstre.id');
    }

    public function test_ekstreden_fatura_adayi_guven_gerekce_ve_otomatik_kayit(): void
    {
        $id = $this->ekstreYukle([
            ['05.10.2026', 'ISKI SU FATURA 2026/09', '312,40'],           // desen + dönem → yüksek, otomatik
            ['08.10.2026', 'IGDAS OTOMATIK ODEME', '845,00'],             // yalnızca desen → orta, otomatik değil
            ['09.10.2026', 'ENERJISA BASKENT EDAS', '640,10'],            // iki sağlayıcı → belirsiz
            ['11.10.2026', 'XYZ DOGALGAZ ODEMESI', '410,00'],             // yalnızca tür kelimesi → düşük
            ['12.10.2026', 'MIGROS KADIKOY', '500,00'],                   // fatura değil
        ]);
        $satirlar = collect($this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()->json('satirlar'));
        [$iski, $igdas, $enerji, $dogalgaz, $market] = $satirlar->all();

        $this->assertSame(['yuksek', $this->s['iski'], '2026-09', 'aciklama', true, true], [
            $iski['fatura_onerisi']['guven'], $iski['fatura_onerisi']['saglayici_id'], $iski['fatura_onerisi']['hizmet_donemi'],
            $iski['fatura_onerisi']['donem_kaynagi'], $iski['fatura_onerisi']['otomatik'], $iski['fatura_onerisi']['secildi'],
        ]);
        $this->assertStringContainsString('"ISKI SU" geçiyor (İSKİ)', $iski['fatura_onerisi']['gerekce'][0]);
        $this->assertStringContainsString('Eylül 2026', $iski['fatura_onerisi']['gerekce'][1]);
        // Dönem açıklamada yok: tahmin edilmez
        $this->assertSame(['orta', null, false], [$igdas['fatura_onerisi']['guven'], $igdas['fatura_onerisi']['hizmet_donemi'], $igdas['fatura_onerisi']['secildi']]);
        $this->assertStringContainsString('dönemi seçin ya da bilinmiyor', end($igdas['fatura_onerisi']['gerekce']));
        $this->assertSame('belirsiz', $enerji['fatura_onerisi']['guven']);
        $this->assertEqualsCanonicalizing([$this->s['enerjisa'], $this->s['ayedas']], $enerji['fatura_onerisi']['adaylar']);
        $this->assertNull($enerji['fatura_onerisi']['saglayici_id']);
        $this->assertSame(['dusuk', [$this->s['igdas']]], [$dogalgaz['fatura_onerisi']['guven'], $dogalgaz['fatura_onerisi']['adaylar']]);
        $this->assertNull($market['fatura_onerisi']);

        // Kullanıcı: İGDAŞ'ı Ekim'e işaretler; belirsizde Enerjisa'yı seçer (dönem bilinmiyor kalır)
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$igdas['id']}", ['fatura' => ['secildi' => true, 'hizmet_donemi' => '2026-09']])->assertOk()
            ->assertJsonPath('islem.fatura_onerisi.donem_kaynagi', 'kullanici');
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$enerji['id']}", ['fatura' => ['secildi' => true]])->assertJsonValidationErrors('fatura');
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$enerji['id']}", ['fatura' => ['secildi' => true, 'saglayici_id' => $this->s['enerjisa']]])->assertOk();

        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.fatura', 3);
        $faturalar = Fatura::orderBy('id')->get();
        $this->assertSame([[$this->s['iski'], '2026-09', 'aciklama'], [$this->s['igdas'], '2026-09', 'kullanici'], [$this->s['enerjisa'], null, null]],
            $faturalar->map(fn (Fatura $f): array => [$f->saglayici_id, $f->hizmet_donemi, $f->donem_kaynagi])->all());
        $this->assertSame(['odendi'], $faturalar->pluck('durum')->map->value->unique()->values()->all());
        $this->assertSame('2026-10-05', $faturalar[0]->odeme_tarihi->toDateString());
        $this->assertSame(312.4, $faturalar[0]->ozet()['tutar']);
        // Bağlanan kart satırı Faturalar kategorisinde; düşük güvenlisi kullanıcı seçmedi, bağlanmadı
        $this->assertTrue(KartIslemi::find($iski['id'])->kategori->fatura);
        $this->assertSame(0, Fatura::where('kart_islemi_id', $dogalgaz['id'])->count());

        // Hizmet ayına göre: Eylül faturaları; kart harcaması işlem ayında (Ekim), fatura ikinci kez sayılmaz
        $eylul = $this->getJson('/api/v1/aile/ozet?ay=2026-09')->assertOk();
        $eylul->assertJsonPath('faturalar.toplam', 1157.4)->assertJsonPath('faturalar.kartla_odenen', 1157.4)->assertJsonPath('gider.toplam', 0);
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gider.toplam', 2707.5)->assertJsonPath('gider.kartsiz_faturalar', 0);
        $this->getJson('/api/v1/aile/faturalar?donem=2026-09')->assertJsonCount(2, 'faturalar')->assertJsonPath('toplam', 1157.4);
        $this->getJson('/api/v1/aile/faturalar?bilinmeyen=1')->assertJsonCount(1, 'faturalar');
        $this->getJson("/api/v1/aile/faturalar?kart_id={$this->kart}")->assertJsonCount(3, 'faturalar');
    }

    public function test_bekleyen_faturaya_baglanir_bag_cozulur_ve_yeniden_baglanir(): void
    {
        // Elle girilmiş bekleyen İGDAŞ Ekim faturası (geçmiş dönem)
        $bekleyen = $this->postJson('/api/v1/aile/faturalar', [
            'saglayici_id' => $this->s['igdas'], 'hizmet_donemi' => '2026-10', 'tutar' => 900, 'son_odeme_tarihi' => '2026-11-10', 'durum' => 'bekliyor',
        ])->assertCreated()->json('fatura');
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['igdas'], 'hizmet_donemi' => '2026-10', 'tutar' => 1, 'durum' => 'bekliyor'])
            ->assertJsonValidationErrors('hizmet_donemi');

        $id = $this->ekstreYukle([['12.11.2026', 'IGDAS DONEM 202610', '905,50'], ['13.11.2026', 'IGDAS ODEME', '100,00']], '13.11.2026');
        $satirlar = collect($this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar'));
        $this->assertSame(['yuksek', '2026-10'], [$satirlar[0]['fatura_onerisi']['guven'], $satirlar[0]['fatura_onerisi']['hizmet_donemi']]);
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.fatura', 1);

        // Yeni fatura açılmadı: bekleyen ödendi oldu, fatura tutarı korunur
        $this->assertSame(1, Fatura::count());
        $fatura = Fatura::find($bekleyen['id']);
        $this->assertSame(['odendi', $satirlar[0]['id'], '2026-11-12', '900.00'], [$fatura->durum->value, $fatura->kart_islemi_id, $fatura->odeme_tarihi->toDateString(), $fatura->tutar]);

        // Yanlış eşleşme: bağ çöz, diğer satıra bağla; aynı satır iki faturaya bağlanamaz
        $this->postJson("/api/v1/aile/faturalar/{$fatura->id}/bagla", ['kart_islemi_id' => $satirlar[1]['id']])->assertJsonValidationErrors('kart_islemi_id');
        $this->postJson("/api/v1/aile/faturalar/{$fatura->id}/coz")->assertOk()->assertJsonPath('fatura.durum', 'bekliyor')->assertJsonPath('fatura.kart_islemi_id', null);
        $this->postJson("/api/v1/aile/faturalar/{$fatura->id}/bagla", ['kart_islemi_id' => $satirlar[1]['id']])->assertOk()->assertJsonPath('fatura.odeme_tarihi', '2026-11-13');
        $diger = $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['igdas'], 'hizmet_donemi' => '2026-09', 'tutar' => 50, 'durum' => 'bekliyor'])->json('fatura');
        $this->postJson("/api/v1/aile/faturalar/{$diger['id']}/bagla", ['kart_islemi_id' => $satirlar[1]['id']])->assertJsonValidationErrors('kart_islemi_id');

        // Ekstre silinince bağlı fatura bekliyor'a döner, silinmez
        $this->deleteJson("/api/v1/aile/ekstreler/{$id}")->assertOk();
        $this->assertSame(['bekliyor', null], [$fatura->fresh()->durum->value, $fatura->fresh()->kart_islemi_id]);
    }

    public function test_kartsiz_odenen_fatura_bir_kez_gider_ve_gecmis_donem(): void
    {
        // Ocak 2025 su faturası (hizmet ayı) bugün giriliyor, Şubat 2025'te nakit ödenmiş
        $this->postJson('/api/v1/aile/faturalar', [
            'saglayici_id' => $this->s['iski'], 'hizmet_donemi' => '2025-01', 'tutar' => 280.15, 'durum' => 'odendi', 'odeme_tarihi' => '2025-02-03',
        ])->assertCreated()->assertJsonPath('fatura.hizmet_donemi_etiketi', 'Ocak 2025');
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['iski'], 'tutar' => 1, 'durum' => 'odendi'])->assertJsonValidationErrors('odeme_tarihi');

        $this->getJson('/api/v1/aile/ozet?ay=2025-01')->assertJsonPath('faturalar.toplam', 280.15)->assertJsonPath('gider.toplam', 0);
        $subat = $this->getJson('/api/v1/aile/ozet?ay=2025-02')->assertOk();
        $subat->assertJsonPath('gider.toplam', 280.15)->assertJsonPath('gider.kartsiz_faturalar', 280.15)->assertJsonPath('faturalar.toplam', 0);
        $this->assertSame('Faturalar', $subat->json('gider.kategoriler.0.ad'));
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('faturalar.toplam', 0);
        $this->getJson('/api/v1/aile/faturalar?yil=2025')->assertJsonCount(1, 'faturalar');
    }

    public function test_bekleyen_ve_geciken_fatura_uyarisi(): void
    {
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['iski'], 'hizmet_donemi' => '2026-09', 'tutar' => 300, 'son_odeme_tarihi' => '2026-11-01', 'durum' => 'bekliyor'])->assertCreated()
            ->assertJsonPath('fatura.gecikmis', true);
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['igdas'], 'hizmet_donemi' => '2026-10', 'tutar' => 700, 'son_odeme_tarihi' => '2026-11-18', 'durum' => 'bekliyor'])->assertCreated();
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['enerjisa'], 'hizmet_donemi' => '2026-10', 'tutar' => 500, 'son_odeme_tarihi' => '2026-12-20', 'durum' => 'bekliyor'])->assertCreated();

        $uyarilar = $this->getJson('/api/v1/aile/ozet')->assertOk()->json('uyarilar');
        $this->assertSame(['İSKİ'], array_column($uyarilar['gecikmis_faturalar'], 'saglayici_ad'));
        $this->assertSame(-12, $uyarilar['gecikmis_faturalar'][0]['gun']);
        $this->assertSame(['İGDAŞ'], array_column($uyarilar['bekleyen_faturalar'], 'saglayici_ad'));
    }

    public function test_saglayici_desen_ve_tur_kurallari(): void
    {
        $this->postJson("/api/v1/aile/saglayicilar/{$this->s['enerjisa']}/desenler", ['desen' => 'İSKİ su'])->assertJsonValidationErrors('desen');
        $this->postJson("/api/v1/aile/saglayicilar/{$this->s['enerjisa']}/desenler", ['desen' => 'enerjisa elk.'])->assertCreated()->assertJsonPath('desen.desen', 'ENERJISA ELK.');
        $this->postJson('/api/v1/aile/fatura-turleri', ['ad' => 'su'])->assertJsonValidationErrors('ad');
        $internet = $this->postJson('/api/v1/aile/fatura-turleri', ['ad' => 'İnternet', 'ikon' => 'pi pi-wifi'])->assertCreated()->json('tur.id');
        $this->getJson('/api/v1/aile/fatura-turleri')->assertJsonCount(4, 'turler');
        $sistem = FaturaTuru::whereNull('hane_id')->value('id');
        $this->deleteJson("/api/v1/aile/fatura-turleri/{$sistem}")->assertForbidden();
        $this->deleteJson("/api/v1/aile/fatura-turleri/{$internet}")->assertOk();
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['iski'], 'tutar' => 10, 'durum' => 'bekliyor'])->assertCreated();
        $this->deleteJson("/api/v1/aile/saglayicilar/{$this->s['iski']}")->assertJsonValidationErrors('saglayici');
    }

    public function test_baska_hane_faturalari_ve_saglayicilari_goremez(): void
    {
        $fatura = $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['iski'], 'hizmet_donemi' => '2026-10', 'tutar' => 300, 'durum' => 'bekliyor'])->json('fatura');
        $benimTurum = $this->postJson('/api/v1/aile/fatura-turleri', ['ad' => 'Aidat'])->json('tur.id');

        $yabanci = User::factory()->create();
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/faturalar')->assertOk()->assertJsonCount(0, 'faturalar');
        $this->getJson('/api/v1/aile/saglayicilar')->assertOk()->assertJsonCount(0, 'saglayicilar');
        $this->getJson('/api/v1/aile/fatura-turleri')->assertJsonCount(3, 'turler'); // yalnızca sistem türleri
        $this->putJson("/api/v1/aile/faturalar/{$fatura['id']}", ['saglayici_id' => $this->s['iski'], 'tutar' => 1, 'durum' => 'bekliyor'])->assertForbidden();
        $this->postJson("/api/v1/aile/faturalar/{$fatura['id']}/coz")->assertForbidden();
        $this->deleteJson("/api/v1/aile/faturalar/{$fatura['id']}")->assertForbidden();
        $this->deleteJson("/api/v1/aile/fatura-turleri/{$benimTurum}")->assertForbidden();
        $this->putJson("/api/v1/aile/saglayicilar/{$this->s['iski']}", ['ad' => 'X', 'fatura_turu_id' => $benimTurum])->assertForbidden();
        $this->postJson('/api/v1/aile/faturalar', ['saglayici_id' => $this->s['iski'], 'tutar' => 1, 'durum' => 'bekliyor'])->assertJsonValidationErrors('saglayici_id');
        $this->postJson('/api/v1/aile/saglayicilar', ['ad' => 'X', 'fatura_turu_id' => $benimTurum])->assertJsonValidationErrors('fatura_turu_id');
        $this->assertSame(1, Fatura::count());
    }
}
