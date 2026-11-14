<?php

namespace Tests\Feature;

use App\Models\Ekstre;
use App\Models\EkstreAiCalismasi;
use App\Models\HaneUyesi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\AIStatement\EkstreAiIsleyici;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFabrikasi;
use Illuminate\Http\Client\Request as IstemciIstegi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrnekPdf;
use Tests\TestCase;

/**
 * Yapay zekâ ile ekstre analizi (kart): anahtar yoksa kapalı, yalnızca onayla ve yalnızca taslakta; PDF input_file
 * olarak gider, metin parçasında kişisel veri yok; arka planda yürür (202 → defer ile gönderim → durum yoklaması /
 * cron); model çıktısı şemaya ve satır satır doğrulanır; her hata türü ayrı ve ekstre bozulmaz; tek etkin çalışma,
 * iptal, zaman aşımı. Gerçek istek ATILMAZ (Http::fake).
 */
class YapayZekaEkstreTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = 'https://api.openai.com/v1/responses';

    private User $ben;

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Storage::fake('local');
        config(['services.openai.key' => 'test-anahtar', 'services.openai.model' => 'test-model', 'services.openai.url' => self::ADRES, 'services.openai.arka_plan' => true]);
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Ornek'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'Kart'])->json('kart.id');
    }

    /** Tanınmayan banka + kişisel satırlar; asgari ödeme yok */
    private function pdf(): string
    {
        return OrnekPdf::yaz([[
            'ORNEK BANKA KREDI KARTI EKSTRESI',
            [40 => 'Ad Soyad', 200 => 'ALI VELI'],
            [40 => 'Kart Numarasi', 200 => '4506 34** **** 4589'],
            [40 => 'Hesap Kesim Tarihi', 200 => '10.11.2026'],
            [40 => 'Son Odeme Tarihi', 200 => '20.11.2026'],
            [40 => 'Donem Borcu', 200 => '2.079,66 TL'],
            [40 => '15.10.2026', 110 => 'MIGROS KADIKOY', 450 => '1.234,56'],
            [40 => '16.10.2026', 110 => 'ENERJISA BASKENT EDAS', 450 => '845,10'],
        ]]);
    }

    private function yukle(?string $pdf = null, bool $yapayZeka = false): TestResponse
    {
        return $this->post('/api/v1/aile/ekstreler', [
            'kart_id' => $this->kart, 'dosya' => UploadedFile::fake()->createWithContent('Ali Veli ekstre.pdf', $pdf ?? $this->pdf()),
            ...($yapayZeka ? ['yapay_zeka' => '1'] : []),
        ], ['Accept' => 'application/json']);
    }

    /**
     * @param  list<array<string, mixed>>  $islemler
     * @return array<string, mixed>
     */
    private function ai(array $islemler, array $ek = []): array
    {
        return [
            'bank_name' => 'Ornek Banka', 'statement_type' => 'credit_card',
            'account' => ['label' => 'Kart', 'currency' => 'TRY'],
            'period' => ['start_date' => null, 'end_date' => null, 'cut_date' => '2026-11-10', 'due_date' => '2026-11-20'],
            'summary' => [
                'previous_balance' => 0, 'total_due' => 2079.66, 'min_payment' => 700.00, 'total_purchases' => 2079.66, 'total_payments' => 0,
                'total_refunds' => null, 'total_interest' => null, 'total_fees' => null, 'total_taxes' => null, 'total_bonus_used' => null,
                'remaining_installments' => null, 'opening_balance' => null, 'closing_balance' => null, 'total_credits' => null, 'total_debits' => null,
            ],
            'transactions' => $islemler, 'installments' => [], 'bonus_transactions' => [],
            'page_audit' => [['page_number' => 1, 'has_transaction_table' => true, 'found_count' => count($islemler), 'status' => 'processed']],
            'warnings' => [], 'review_required' => false,
            ...$ek,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function islem(string $tarih, string $aciklama, float $tutar, string $kaynak, string $tur = 'purchase'): array
    {
        return [
            'date' => $tarih, 'description' => $aciklama, 'amount' => $tutar, 'currency' => 'TRY',
            'direction' => in_array($tur, ['payment', 'refund', 'bonus_redemption', 'deposit'], true) ? 'credit' : 'debit',
            'transaction_type' => $tur, 'original_currency' => null, 'original_amount' => null, 'balance' => null, 'bonus_earned' => null,
            'source_page' => 1, 'source_text' => $kaynak, 'confidence' => 0.95, 'needs_review' => false, 'review_reason' => null,
            'installment_details' => ['number' => null, 'count' => null, 'total_amount' => null, 'remaining_amount' => null],
        ];
    }

    /** Tamamlanmış Responses API yanıtının gövdesi */
    private function tamam(array $ai, string $id = 'resp_1'): array
    {
        return ['id' => $id, 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($ai)]]]], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 300]];
    }

    private function cevap(array $ai, string $id = 'resp_1'): PromiseInterface
    {
        return Http::response($this->tamam($ai, $id));
    }

    private function calistir(int $id, bool $onay = true): TestResponse
    {
        return $this->postJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka", $onay ? ['onay' => true] : []);
    }

    private function durum(int $id): TestResponse
    {
        return $this->getJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka")->assertOk();
    }

    private function gecerli(): array
    {
        return $this->ai([
            $this->islem('2026-10-15', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'),
            $this->islem('2026-10-16', 'ENERJISA BASKENT EDAS', 845.10, '16.10.2026 ENERJISA BASKENT EDAS 845,10'),
            $this->islem('2026-10-17', 'HAYALI MAGAZA', 999.99, '17.10.2026 HAYALI MAGAZA 999,99'), // PDF'te yok
        ]);
    }

    public function test_kapali_onaysiz_ve_onayli_ekstrede_calismaz(): void
    {
        Http::fake();
        $id = $this->yukle()->assertCreated()->json('ekstre.id');

        $this->calistir($id, onay: false)->assertJsonValidationErrors('onay');
        config(['services.openai.key' => '']);
        $this->calistir($id)->assertJsonValidationErrors('ekstre');
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertJsonPath('ai_acik', false);
        $this->yukle(OrnekPdf::metinsiz(), yapayZeka: true)->assertJsonValidationErrors('yapay_zeka');
        config(['services.openai.key' => 'test-anahtar']);
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertJsonPath('ai_acik', true)->assertJsonPath('ai_calisma', null);

        Ekstre::find($id)->update(['durum' => 'onaylandi']);
        $this->calistir($id)->assertJsonValidationErrors('ekstre');
        Http::assertNothingSent();
        $this->assertSame(0, EkstreAiCalismasi::count());
    }

    public function test_arka_planda_yurur_dogrular_uydurma_satiri_reddeder_ve_kisisel_veri_gitmez(): void
    {
        Log::spy();
        Http::fake([
            self::ADRES => Http::response(['id' => 'resp_1', 'status' => 'queued']),
            self::ADRES.'/resp_1' => Http::sequence()->push(['id' => 'resp_1', 'status' => 'in_progress'])->push($this->tamam($this->gecerli())),
        ]);
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        $this->assertSame('hesaplandi', $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('ekstre.alan_kaynaklari.asgari_odeme'));

        // Başlat: 202 hemen döner; gönderim yanıttan sonra (defer): arka plan modunda oluşturuldu, sırada
        $this->calistir($id)->assertStatus(202)->assertJsonPath('calisma.durum', 'bekliyor')->assertJsonPath('calisma.etkin', true);
        $kayit = EkstreAiCalismasi::first();
        $this->assertSame(['calisiyor', 'okunuyor', 'resp_1', 'arka_plan', EkstreAiIsleyici::ONAY_SURUMU], [$kayit->durum, $kayit->asama, $kayit->openai_yanit_id, $kayit->calistirici, $kayit->onay_surumu]);
        $this->assertNotNull($kayit->onay_at);

        // Sürerken: ikinci başlatma, satır / alan düzeltme ve onay yok
        $this->calistir($id)->assertJsonValidationErrors('ekstre');
        $satir = $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar.0.id');
        $this->putJson("/api/v1/aile/ekstreler/{$id}/satirlar/{$satir}", ['aciklama' => 'X'])->assertJsonValidationErrors('ekstre');
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertJsonValidationErrors('ekstre');

        // Yoklama: 5 sn'den sık sorulmaz; sonra hâlâ sürüyor; sonra tamamlandı
        $this->durum($id)->assertJsonPath('calisma.durum', 'calisiyor');
        Http::assertSentCount(1);
        Carbon::setTestNow(now()->addSeconds(6));
        $this->durum($id)->assertJsonPath('calisma.asama', 'okunuyor');
        Carbon::setTestNow(now()->addSeconds(6));
        $this->durum($id)->assertJsonPath('calisma.durum', 'basarili')->assertJsonPath('calisma.kabul_satir', 2)->assertJsonPath('calisma.reddedilen_satir', 1)
            ->assertJsonPath('ekstre.banka_profili', 'ai')
            ->assertJsonPath('ekstre.asgari_odeme', 700)
            ->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'ai')
            ->assertJsonPath('ekstre.denetim.ai.reddedilen.0.neden', 'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).')
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sayfa_uyusuyor', true)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sayfalar.0.ayristirilan_islem', 2)
            ->assertJsonPath('ekstre.denetim.php.durum', 'kontrol_edilemedi'); // PHP okuyucunun sonucu korunur

        $satirlar = collect($this->getJson("/api/v1/aile/ekstreler/{$id}")->assertJsonPath('ai_calisma.durum', 'basarili')->json('satirlar'));
        $this->assertSame(['MIGROS KADIKOY', 'ENERJISA BASKENT EDAS'], $satirlar->pluck('aciklama')->all());
        $this->assertSame([1234.56, 845.1], $satirlar->pluck('tutar')->all());
        $this->assertSame(['taslak', 'taslak'], $satirlar->pluck('durum')->all()); // finans hareketi yazılmadı

        // İstek: arka plan modu, PDF input_file (base64, genel dosya adı — yüklenen adda kişi adı var —, detail=high) +
        // temizlenmiş sayfa metni, strict şema, store:false, çıktı sınırı, anahtar başlıkta; metin parçasında kişisel veri yok
        Http::assertSent(function (IstemciIstegi $i): bool {
            if ($i->method() !== 'POST' || $i->url() !== self::ADRES) {
                return false;
            }
            $govde = $i->data();
            [$dosya, $metin] = $govde['input'][1]['content'];
            $pdf = base64_decode(substr($dosya['file_data'], strlen('data:application/pdf;base64,')), true);

            return $i->hasHeader('Authorization', 'Bearer test-anahtar')
                && $govde['model'] === 'test-model' && $govde['store'] === false && $govde['background'] === true
                && $govde['max_output_tokens'] === 32000
                && $govde['text']['format']['type'] === 'json_schema' && $govde['text']['format']['strict'] === true
                && $govde['text']['format']['schema']['additionalProperties'] === false
                && $dosya['type'] === 'input_file' && $dosya['filename'] === 'ekstre.pdf' && $dosya['detail'] === 'high'
                && str_starts_with((string) $pdf, '%PDF-')
                && $metin['type'] === 'input_text' && str_contains($metin['text'], '[SAYFA 1]') && str_contains($metin['text'], 'MIGROS KADIKOY')
                && ! str_contains($metin['text'], '4506') && ! str_contains($metin['text'], 'ALI VELI');
        });

        // JSON görünümü maskeli; çalışma kaydı içeriksiz, token sayısı var; log yok
        $this->getJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka/json")->assertOk()->assertJsonPath('ai_cikti.bank_name', 'Ornek Banka')
            ->assertJsonPath('ai_cikti.transactions.0.source_text', '15.10.2026 MIGROS KADIKOY 1.234,56');
        $kayit = EkstreAiCalismasi::first();
        $this->assertSame(['basarili', 'tamam', null, 1200, 300], [$kayit->durum, $kayit->asama, $kayit->kilit, $kayit->girdi_token, $kayit->cikti_token]);
        $this->assertEmpty(array_intersect(['metin', 'icerik', 'govde', 'cikti'], array_keys($kayit->getAttributes())));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');

        // Onay: yalnızca incelemeden sonra, mevcut akışla
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 2);
    }

    public function test_cron_devralir_zaman_asimi_ve_iptal_istegi(): void
    {
        Http::fake([
            self::ADRES => Http::response(['id' => 'resp_7', 'status' => 'queued']),
            self::ADRES.'/resp_7' => Http::response(['id' => 'resp_7', 'status' => 'in_progress']),
            self::ADRES.'/resp_7/cancel' => Http::response(['id' => 'resp_7', 'status' => 'cancelled']),
        ]);
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        // defer hiç çalışmamış gibi: kayıt açıldı, gönderilmedi
        $kayit = app(EkstreAiIsleyici::class)->baslat(Ekstre::find($id), $this->ben);
        $this->artisan('ekstre:ai-isle')->assertSuccessful();
        $this->assertSame('bekliyor', $kayit->fresh()->durum); // 30 sn defer'e süre tanınır
        Carbon::setTestNow(now()->addSeconds(31));
        $this->artisan('ekstre:ai-isle')->assertSuccessful();
        $this->assertSame(['calisiyor', 'resp_7'], [$kayit->fresh()->durum, $kayit->fresh()->openai_yanit_id]);

        // 15 dakikayı aşan çalışma zaman aşımına düşer, OpenAI'deki yanıt iptal edilir
        Carbon::setTestNow(now()->addMinutes(16));
        $this->artisan('ekstre:ai-isle')->assertSuccessful();
        $this->assertSame(['hata', 'zaman_asimi'], [$kayit->fresh()->durum, $kayit->fresh()->hata_kodu]);
        Http::assertSent(fn (IstemciIstegi $i): bool => $i->url() === self::ADRES.'/resp_7/cancel');
        $this->durum($id)->assertJsonPath('calisma.etkin', false)->assertJsonPath('calisma.mesaj', 'Yapay zekâ servisi zamanında yanıt vermedi. Yeniden deneyin; ekstreniz olduğu gibi duruyor.');
    }

    public function test_arka_plan_modu_kabul_edilmezse_cron_senkron_calistirir(): void
    {
        Http::fake([self::ADRES => Http::sequence()
            ->push(['error' => ['message' => "Background mode can't be used with store=false"]], 400)
            ->push($this->tamam($this->gecerli()))]);
        $id = $this->yukle()->assertCreated()->json('ekstre.id');

        $this->calistir($id)->assertStatus(202);
        $kayit = EkstreAiCalismasi::first();
        $this->assertSame(['bekliyor', 'cron'], [$kayit->durum, $kayit->calistirici]);
        // Durum ucu senkron çağrıyı web isteğinde yapmaz
        $this->durum($id)->assertJsonPath('calisma.durum', 'bekliyor');
        $this->artisan('ekstre:ai-isle')->assertSuccessful();
        $this->assertSame(['basarili', 2], [$kayit->fresh()->durum, $kayit->fresh()->kabul_satir]);
        Http::assertSent(fn (IstemciIstegi $i): bool => $i->method() === 'POST' && ! isset($i->data()['background']));
    }

    public function test_hata_turleri_ayri_ele_alinir_ve_ekstre_bozulmaz(): void
    {
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        $satirSayisi = fn (): int => count($this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar'));
        $onceki = $satirSayisi();

        $durumlar = [
            'hiz_siniri' => Http::response([], 429),
            'http_500' => Http::response(['hata' => 'GIZLI ICERIK'], 500),
            'http_401' => Http::response([], 401),
            'model_reddi' => Http::response(['id' => 'r', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'hayir']]]]]),
            'yarim' => Http::response(['id' => 'r', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []]),
            'model_hatasi' => Http::response(['id' => 'r', 'status' => 'failed', 'error' => ['message' => 'GIZLI ICERIK']]),
            'bozuk_json' => Http::response(['id' => 'r', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{bozuk']]]]]),
            'sema' => $this->cevap(['bank_name' => 'x']), // zorunlu alanlar yok
            'baglanti' => Http::failedConnection(),
        ];
        foreach ($durumlar as $kod => $yanit) {
            // Http::fake tekrar çağrılınca ilk sahte yanıt kalır; her tur için temiz fabrika. Başlatma dakikada 10 kez
            // (throttle:ekstre): her tur bir dakika sonra
            Http::swap(new HttpFabrikasi);
            Http::fake(['*' => $yanit]);
            Carbon::setTestNow(now()->addMinute());
            $this->calistir($id)->assertStatus(202);
            $cevap = $this->durum($id)->assertJsonPath('calisma.durum', 'hata')->assertJsonPath('calisma.hata_kodu', $kod);
            $this->assertStringNotContainsString('GIZLI ICERIK', $cevap->getContent());
            $this->assertNotEmpty($cevap->json('calisma.mesaj'));
        }
        // Zaman aşımı
        Http::swap(new HttpFabrikasi);
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds')]);
        $this->calistir($id)->assertStatus(202);
        $this->assertSame('zaman_asimi', EkstreAiCalismasi::latest('id')->first()->hata_kodu);

        $this->assertSame($onceki, $satirSayisi());
        $this->assertSame('genel', Ekstre::find($id)->banka_profili);
        $this->assertNotNull(Ekstre::find($id)->dosya_yolu); // API hatasında PDF kaybolmaz
        Storage::disk('local')->assertExists(Ekstre::find($id)->dosya_yolu);
    }

    public function test_sema_disi_alan_ve_yanlis_tur_reddedilir(): void
    {
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        $fazla = $this->gecerli();
        $fazla['uydurma_alan'] = 1;
        Http::fake(['*' => $this->cevap($fazla)]);
        $this->calistir($id)->assertStatus(202);
        $this->assertSame('sema', EkstreAiCalismasi::latest('id')->first()->hata_kodu);

        $yanlis = $this->gecerli();
        $yanlis['transactions'][0]['amount'] = '1.234,56'; // sayı değil, metin
        Http::swap(new HttpFabrikasi);
        Http::fake(['*' => $this->cevap($yanlis)]);
        $this->calistir($id)->assertStatus(202);
        $this->assertSame('sema', EkstreAiCalismasi::latest('id')->first()->hata_kodu);
    }

    public function test_satir_dogrulama_kurallari(): void
    {
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        $kotu = [
            $this->islem('2026-02-31', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'),       // tarih
            $this->islem('2026-10-15', 'MIGROS KADIKOY', 1.0, '15.10.2026 MIGROS KADIKOY 1.234,56'),            // tutar satırda yok
            [...$this->islem('2026-10-15', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'), 'currency' => 'USD'],
            [...$this->islem('2026-10-15', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'), 'source_page' => 9],
            $this->islem('2026-10-16', 'ENERJISA BASKENT EDAS', 845.10, '16.10.2026 ENERJISA BASKENT EDAS 845,10', 'deposit'), // banka hesabı türü
            $this->islem('2026-10-16', 'ENERJISA BASKENT EDAS', 845.10, '16.10.2026 ENERJISA BASKENT EDAS 845,10', 'refund'), // geçerli (iade)
        ];
        Http::fake(['*' => $this->cevap($this->ai($kotu))]);

        $this->calistir($id)->assertStatus(202);
        $cevap = $this->durum($id)->assertJsonPath('calisma.kabul_satir', 1)->assertJsonPath('calisma.reddedilen_satir', 5);
        $nedenler = array_column($cevap->json('ekstre.denetim.ai.reddedilen'), 'neden');
        $this->assertSame(['Tarih geçersiz.', 'Tutar, kaynak satırda yazmıyor.', 'Para birimi TL değil.', 'Kaynak sayfa geçersiz.', 'Banka hesabı hareketi kredi kartı ekstresinde olamaz.'], $nedenler);
        $this->assertSame('iade', $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar.0.tur'));
    }

    public function test_iptal_edilen_calismanin_sonucu_yazilmaz(): void
    {
        Http::fake([
            self::ADRES => Http::response(['id' => 'resp_3', 'status' => 'queued']),
            self::ADRES.'/resp_3' => $this->cevap($this->gecerli(), 'resp_3'),
            self::ADRES.'/resp_3/cancel' => Http::response(['id' => 'resp_3', 'status' => 'cancelled']),
        ]);
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        $once = $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar');
        $this->calistir($id)->assertStatus(202);
        $this->postJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka/iptal")->assertOk()->assertJsonPath('calisma.durum', 'iptal');
        $this->postJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka/iptal")->assertJsonValidationErrors('ekstre');
        Http::assertSent(fn (IstemciIstegi $i): bool => $i->url() === self::ADRES.'/resp_3/cancel');

        // Sonradan gelen (tamamlanmış) yanıt yazılmaz, sorulmaz bile
        Carbon::setTestNow(now()->addSeconds(10));
        $this->durum($id)->assertJsonPath('calisma.durum', 'iptal');
        $this->artisan('ekstre:ai-isle')->assertSuccessful();
        $this->assertSame($once, $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar'));
        $this->assertSame('genel', Ekstre::find($id)->banka_profili);
        $this->getJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka/json")->assertNotFound();
    }

    public function test_taranmis_pdf_yapay_zeka_onayiyla_yuklenir_ve_analiz_baslar(): void
    {
        Http::fake(['*' => Http::response(['id' => 'resp_5', 'status' => 'queued'])]);
        $this->yukle(OrnekPdf::metinsiz())->assertJsonValidationErrors('dosya');
        $cevap = $this->yukle(OrnekPdf::metinsiz(), yapayZeka: true)->assertCreated()
            ->assertJsonPath('ozet.satir', 0)->assertJsonPath('calisma.durum', 'bekliyor');
        $this->assertStringContainsString('yapay zekâ analizi başladı', $cevap->json('message'));
        $this->assertSame(['calisiyor', 'resp_5'], [EkstreAiCalismasi::first()->durum, EkstreAiCalismasi::first()->openai_yanit_id]);
        // Sayfa metni boş gider; PDF görüntüsünden okunur
        Http::assertSent(fn (IstemciIstegi $i): bool => str_contains($i->data()['input'][1]['content'][1]['text'], "[SAYFA 1]\n\n"));
    }

    public function test_baska_hane_yapay_zekayi_calistiramaz_goremez(): void
    {
        Http::fake(['*' => $this->cevap($this->gecerli())]);
        $id = $this->yukle()->assertCreated()->json('ekstre.id');
        $this->calistir($id)->assertStatus(202);
        $this->actingAs(User::factory()->create());
        $this->calistir($id)->assertForbidden();
        $this->getJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka")->assertForbidden();
        $this->getJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka/json")->assertForbidden();
        $this->postJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka/iptal")->assertForbidden();
        $this->assertSame(1, EkstreAiCalismasi::count());
    }
}
