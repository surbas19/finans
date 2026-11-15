<?php

namespace Tests\Feature;

use App\Models\Ekstre;
use App\Models\EkstreAiCalismasi;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\AIStatement\HariciIstem;
use App\Services\AIStatement\JsonValidator;
use App\Services\AIStatement\StatementAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\GarantiAiYaniti;
use Tests\Support\OrnekPdf;
use Tests\TestCase;

/**
 * Dış yapay zekâ ile ekstre (prompt kopyala → JSON yapıştır): Garanti referans JSON'u (GarantiAiYaniti) PDF'siz ve
 * PDF'li yapıştırılır; sunucu hiçbir dış servise istek atmaz (Http::fake + assertNothingSent), OPENAI_API_KEY gerekmez.
 */
class HariciJsonEkstreTest extends TestCase
{
    use RefreshDatabase;

    private const KLASOR = __DIR__.'/../Fixtures/ekstre/garanti_bonus';

    private User $ben;

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 10:00:00');
        Storage::fake('local');
        Http::fake();
        config(['services.openai.key' => null, 'ekstre.ai_aktarim' => true]);
        [$this->ben, $this->kart] = $this->hane();
        $this->actingAs($this->ben);
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function hane(): array
    {
        $kullanici = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($kullanici);
        $this->actingAs($kullanici);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti BBVA'])->json('banka.id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => HaneUyesi::where('user_id', $kullanici->id)->value('id'), 'ad' => 'Bonus'])->json('kart.id');

        return [$kullanici, $kart];
    }

    /**
     * @return array<string, mixed>
     */
    private static function beklenen(): array
    {
        return json_decode((string) file_get_contents(self::KLASOR.'/beklenen.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function garantiPdf(): string
    {
        $s = json_decode((string) file_get_contents(self::KLASOR.'/sayfalar.json'), true, flags: JSON_THROW_ON_ERROR);

        return OrnekPdf::yaz($s['sayfalar'], false, $s['satir_araligi']);
    }

    /**
     * @return array<string, mixed>
     */
    private function garantiJson(?string $pdf = null): array
    {
        $pdf ??= $this->garantiPdf();

        return GarantiAiYaniti::olustur(self::beklenen(), app(StatementAnalyzer::class)->sayfaMetinleri($pdf)['sayfa_metinleri']);
    }

    /**
     * @param  array<string, mixed>|string  $json
     */
    private function yapistir(array|string $json, ?string $pdf = null, ?int $kart = null): TestResponse
    {
        return $this->post('/api/v1/aile/ekstreler/json-ile', [
            'kart_id' => $kart ?? $this->kart,
            'json' => is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE),
            ...($pdf !== null ? ['dosya' => UploadedFile::fake()->createWithContent('ekstre.pdf', $pdf)] : []),
        ], ['Accept' => 'application/json']);
    }

    public function test_istem_semadan_birlestirilir_ve_oturum_ister(): void
    {
        $cevap = $this->getJson('/api/v1/aile/ekstre-istemi')->assertOk()->assertJsonPath('sema_surumu', 'v2');
        $istem = $cevap->json('istem');
        $sema = json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true);
        // Şemadaki her üst düzey ve işlem anahtarı istemde geçer; yer tutucular doldurulmuş
        foreach ([...array_keys($sema['properties']), ...array_keys($sema['properties']['transactions']['items']['properties'])] as $anahtar) {
            $this->assertStringContainsString("\"{$anahtar}\"", $istem, $anahtar);
        }
        $this->assertStringNotContainsString('{{', $istem);
        foreach (['YALNIZCA tek bir JSON', 'source_text', 'bonus_transactions', 'page_audit', 'Borç denklemi', 'null', 'YYYY-MM-DD', 'talimat'] as $kural) {
            $this->assertStringContainsString($kural, $istem, $kural);
        }
        // Boş örnek şablon şemaya uyar
        $this->assertSame([], app(JsonValidator::class)->dogrula(app(HariciIstem::class)->ornek(), $sema));

        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/aile/ekstre-istemi')->assertUnauthorized();
    }

    public function test_pdfsiz_yapistirma_75_satir_mutabakat_tutar_satirlar_inceleme_istemez_ve_onaylanir(): void
    {
        $cevap = $this->yapistir($this->garantiJson())->assertCreated()
            ->assertJsonPath('ozet', ['satir' => 75, 'inceleme' => 0, 'reddedilen' => 0])
            ->assertJsonPath('ekstre.banka_profili', 'ai')
            ->assertJsonPath('ekstre.donem_borcu', 100632.22)
            ->assertJsonPath('ekstre.dosya_var', false)
            ->assertJsonPath('ekstre.denetim.durum', 'tutuyor')
            ->assertJsonPath('ekstre.denetim.ai.model', 'harici')
            ->assertJsonPath('ekstre.denetim.ai.harici', true)
            ->assertJsonPath('ekstre.denetim.ai.metinsiz', true);
        $this->assertStringContainsString('PDF\'siz', $cevap->json('uyarilar.0'));
        $id = $cevap->json('ekstre.id');

        $goster = $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()
            ->assertJsonPath('ai_calisma.durum', 'basarili')
            ->assertJsonPath('ai_calisma.kabul_satir', 75)
            ->assertJsonPath('ai_calisma.model', 'harici')
            ->assertJsonPath('ai_acik', false)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sorunlu_sayfa', 0)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.kaynak_metni', false);
        $this->assertSame(['tamam', 'tamam', 'tamam', 'tamam'], array_column($goster->json('ekstre.denetim.ai.sayfa_denetimi.sayfalar'), 'durum'));
        $this->assertCount(75, $goster->json('satirlar'));
        $this->assertSame([], array_filter($goster->json('satirlar'), fn (array $s): bool => $s['okuma']['inceleme']));
        $this->assertSame('harici', EkstreAiCalismasi::where('ekstre_id', $id)->value('model'));
        $this->assertSame('yapistirma', EkstreAiCalismasi::where('ekstre_id', $id)->value('calistirici'));

        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 75)->assertJsonPath('rapor.atlanan', 0);
        Http::assertNothingSent();
    }

    public function test_pdfli_yapistirma_kaynak_satirlari_sunucuda_dogrulanir_ve_pdf_saklanir(): void
    {
        $pdf = $this->garantiPdf();
        $cevap = $this->yapistir($this->garantiJson($pdf), $pdf)->assertCreated()
            ->assertJsonPath('ozet', ['satir' => 75, 'inceleme' => 0, 'reddedilen' => 0])
            ->assertJsonPath('ekstre.dosya_var', true)
            ->assertJsonPath('ekstre.sayfa_sayisi', 4)
            ->assertJsonPath('ekstre.denetim.ai.metinsiz', false)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.kaynak_metni', true);
        $id = $cevap->json('ekstre.id');
        $this->assertSame([], $cevap->json('uyarilar'));
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sorunlu_sayfa', 0);
        $this->assertTrue(Storage::disk('local')->exists(Ekstre::findOrFail($id)->dosya_yolu));
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 75);

        // PDF'li yolda uydurma kaynak satır reddedilir (PDF'siz yolda doğrulanamazdı)
        [$ikinci, $kart2] = $this->hane();
        $json = $this->garantiJson($pdf);
        $json['transactions'][0]['source_text'] = '01.01.2026 OLMAYAN ISYERI '.number_format((float) $json['transactions'][0]['amount'], 2, ',', '.');
        $this->yapistir($json, $pdf, $kart2)->assertCreated()->assertJsonPath('ozet.reddedilen', 1)->assertJsonPath('ozet.satir', 74);
    }

    public function test_pdfsiz_ozet_tutmuyorsa_tum_satirlar_inceleme_ister_ve_kontrol_ettim_kapisi(): void
    {
        $json = $this->garantiJson();
        $json['summary']['total_due'] += 10;
        $cevap = $this->yapistir($json)->assertCreated()->assertJsonPath('ozet', ['satir' => 75, 'inceleme' => 75, 'reddedilen' => 0])
            ->assertJsonPath('ekstre.denetim.durum', 'tutmuyor');
        $this->assertStringContainsString('tutmuyor', implode(' ', $cevap->json('uyarilar')));
        $id = $cevap->json('ekstre.id');
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertUnprocessable()->assertJsonValidationErrors('ekstre');

        $satirlar = KartIslemi::where('ekstre_id', $id)->get();
        foreach ($satirlar as $i) {
            $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$i->id}", ['incelendi' => true])->assertOk();
        }
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 75);
    }

    public function test_citli_json_kabul_edilir_bozuk_ve_fazla_anahtarli_json_reddedilir(): void
    {
        $json = json_encode($this->garantiJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // Markdown çiti ve BOM soyulur
        $this->yapistir("\xEF\xBB\xBF```json\n{$json}\n```\n")->assertCreated()->assertJsonPath('ozet.satir', 75);

        [, $kart2] = $this->hane();
        $this->yapistir('{"bank_name": "X", ', null, $kart2)->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->yapistir('Tabii, işte JSON: {}', null, $kart2)->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->yapistir('[1,2]', null, $kart2)->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->yapistir('{}', null, $kart2)->assertUnprocessable()->assertJsonValidationErrors('json');

        $fazla = $this->garantiJson();
        $fazla['yorum'] = 'selam';
        $fazla['transactions'][2]['kategori'] = 'market';
        $hatalar = $this->yapistir($fazla, null, $kart2)->assertUnprocessable()->json('errors.json');
        $this->assertContains('yorum: fazla anahtar (şemada yok, silin)', $hatalar);
        $this->assertContains('transactions.2.kategori: fazla anahtar (şemada yok, silin)', $hatalar);

        // Sayı string gelirse hata yolu verilir; otomatik düzeltme yok
        $metin = $this->garantiJson();
        $metin['transactions'][3]['amount'] = '1.234,56';
        $this->assertContains('transactions.3.amount: sayı olmalı', $this->yapistir($metin, null, $kart2)->assertUnprocessable()->json('errors.json'));
        $this->assertSame(1, Ekstre::count());
    }

    public function test_banka_hesabi_ekstresi_ve_bilinmeyen_belge_reddedilir(): void
    {
        $banka = $this->garantiJson();
        $banka['statement_type'] = 'bank_account';
        $this->assertStringContainsString('banka hesabı', $this->yapistir($banka)->assertUnprocessable()->json('errors.json.0'));
        $bilinmeyen = $this->garantiJson();
        $bilinmeyen['statement_type'] = 'unknown';
        $this->yapistir($bilinmeyen)->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->assertSame(0, Ekstre::count());
    }

    public function test_boyut_siniri_ve_islemsiz_json(): void
    {
        $this->yapistir(str_repeat(' ', 2 * 1024 * 1024 + 1))->assertUnprocessable()->assertJsonValidationErrors('json');
        $bos = $this->garantiJson();
        $bos['transactions'] = [];
        $this->assertStringContainsString('İşlem satırı yok', $this->yapistir($bos)->assertUnprocessable()->json('errors.json.0'));
        // Hiçbir satır doğrulamadan geçmezse (tutar kaynak satırda yok) neden listelenir
        $hepsi = $this->garantiJson();
        $hepsi['transactions'] = array_map(fn (array $t): array => [...$t, 'amount' => $t['amount'] + 0.07], array_slice($hepsi['transactions'], 0, 3));
        $liste = $this->yapistir($hepsi)->assertUnprocessable()->json('errors.json');
        $this->assertStringContainsString('Tutar, kaynak satırda yazmıyor', implode(' ', $liste));
        $this->assertSame(0, Ekstre::count());
    }

    public function test_kismen_gecersiz_satir_alinmaz_digerleri_taslak_olur(): void
    {
        $json = $this->garantiJson();
        $json['transactions'][5]['amount'] += 0.07;
        $this->yapistir($json)->assertCreated()->assertJsonPath('ozet.satir', 74)->assertJsonPath('ozet.reddedilen', 1)
            ->assertJsonPath('ozet.inceleme', 74) // 1 satır eksik: mutabakat tutmuyor
            ->assertJsonPath('ekstre.denetim.ai.reddedilen.0.neden', 'Tutar, kaynak satırda yazmıyor.');
    }

    public function test_pdfsiz_ayni_kaynak_satir_ikinci_kez_gelirse_yalniz_o_satir_inceleme_ister(): void
    {
        $json = $this->garantiJson();
        $kopya = $json['transactions'][4];
        array_splice($json['transactions'], 5, 0, [$kopya]);
        $json['summary']['total_due'] += $kopya['amount']; // mutabakat korunur (ekstredeki gerçek satır iki kez geçiyor gibi)
        $json['summary']['total_purchases'] += $kopya['amount'];
        $json['page_audit'][0]['found_count']++;
        $cevap = $this->yapistir($json)->assertCreated()->assertJsonPath('ozet.satir', 76)->assertJsonPath('ozet.inceleme', 1);
        $isaretli = array_values(array_filter(KartIslemi::where('ekstre_id', $cevap->json('ekstre.id'))->get()->all(), fn (KartIslemi $i): bool => $i->okuma['inceleme']));
        $this->assertCount(1, $isaretli);
        $this->assertStringContainsString('ikinci kez', implode(' ', $isaretli[0]->okuma['uyarilar']));
    }

    public function test_ayni_json_ikinci_kez_eklenmez_pdf_ise_ayni_pdf_ikinci_kez_eklenmez(): void
    {
        $json = $this->garantiJson();
        $this->yapistir($json)->assertCreated();
        $this->yapistir($json)->assertUnprocessable()->assertJsonValidationErrors('json');
        // Çit / boşluk farkı aynı JSON sayılır
        $this->yapistir("```json\n".json_encode($json, JSON_UNESCAPED_UNICODE)."\n```")->assertUnprocessable()->assertJsonValidationErrors('json');
        $this->assertSame(1, Ekstre::count());

        $pdf = $this->garantiPdf();
        [, $kart2] = $this->hane();
        $this->yapistir($this->garantiJson($pdf), $pdf, $kart2)->assertCreated();
        $this->yapistir($this->garantiJson($pdf), $pdf, $kart2)->assertUnprocessable()->assertJsonValidationErrors('dosya');
        $this->assertSame(2, Ekstre::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('ekstreler'));
    }

    public function test_baska_hanenin_kartina_yapistirilamaz_ve_taslagi_baska_hane_goremez(): void
    {
        $kartim = $this->kart;
        [$baska, $baskaKart] = $this->hane();
        // Başka hane, benim kartıma yapıştıramaz
        $this->yapistir($this->garantiJson(), null, $kartim)->assertUnprocessable()->assertJsonValidationErrors('kart_id');
        $this->assertSame(0, Ekstre::count());

        $this->actingAs($this->ben);
        $id = $this->yapistir($this->garantiJson(), null, $kartim)->assertCreated()->json('ekstre.id');
        $satir = KartIslemi::where('ekstre_id', $id)->value('id');

        $this->actingAs($baska);
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertForbidden();
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$satir}", ['incelendi' => true])->assertForbidden();
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertForbidden();
        $this->deleteJson("/api/v1/aile/ekstreler/{$id}")->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/aile/ekstreler')->assertOk()->json('ekstreler'));
        // Hanesi olmayan kullanıcı da yapıştıramaz
        $this->actingAs(User::factory()->create());
        $this->yapistir($this->garantiJson(), null, $baskaKart)->assertUnprocessable();
        $this->assertSame(1, Ekstre::count());
    }

    public function test_kisisel_veri_maskelenir_saklanan_cikti_ve_satirlarda(): void
    {
        $json = $this->garantiJson();
        $satir = &$json['transactions'][0];
        $tutar = number_format((float) $satir['amount'], 2, ',', '.');
        $satir['description'] = 'ODEME 4506 3456 7890 4589 ALI 05321234567';
        $satir['source_text'] .= ' TR330006100519786457841326 '.$tutar;
        unset($satir);
        $json['warnings'] = ['Ali Veli ali@ornek.com'];
        $id = $this->yapistir($json)->assertCreated()->json('ekstre.id');

        $ilk = KartIslemi::where('ekstre_id', $id)->orderBy('sira')->first();
        $this->assertStringContainsString('[KART]', $ilk->aciklama);
        $this->assertStringContainsString('[TELEFON]', $ilk->aciklama);
        $this->assertStringNotContainsString('4506', $ilk->aciklama.$ilk->ham_metin);
        $this->assertStringContainsString('[IBAN]', $ilk->ham_metin);
        $this->assertStringNotContainsString('TR3300', $ilk->ham_metin);
        $saklanan = json_encode(Ekstre::findOrFail($id)->only(['ai_cikti', 'denetim']), JSON_UNESCAPED_UNICODE);
        foreach (['4506 3456', '05321234567', 'ali@ornek.com', 'TR330006'] as $kisisel) {
            $this->assertStringNotContainsString($kisisel, $saklanan, $kisisel);
        }
    }

    public function test_json_satirlar_ve_tutarlar_loglanmaz_ve_dis_istek_yoktur(): void
    {
        $mesajlar = [];
        Event::listen(MessageLogged::class, function (MessageLogged $olay) use (&$mesajlar): void {
            $mesajlar[] = $olay->message.' '.json_encode($olay->context, JSON_UNESCAPED_UNICODE);
        });
        $json = $this->garantiJson();
        $json['transactions'][1]['description'] = 'GIZLIMAGAZA';
        $this->yapistir($json)->assertCreated();
        $this->yapistir('{"bozuk": GIZLIMAGAZA')->assertUnprocessable();
        $json['transactions'][0]['amount'] = 'x';
        $this->yapistir($json)->assertUnprocessable();

        $this->assertStringNotContainsString('GIZLIMAGAZA', implode("\n", $mesajlar));
        $this->assertStringNotContainsString('100632', implode("\n", $mesajlar));
        Http::assertNothingSent();
    }

    public function test_pdf_olmayan_dosya_reddedilir(): void
    {
        $this->post('/api/v1/aile/ekstreler/json-ile', [
            'kart_id' => $this->kart, 'json' => json_encode($this->garantiJson()),
            'dosya' => UploadedFile::fake()->createWithContent('x.pdf', 'bu bir pdf degil'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, Ekstre::count());
    }
}
