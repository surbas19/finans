<?php

namespace Tests\Feature;

use App\Models\Ekstre;
use App\Models\EkstreAiCalismasi;
use App\Models\HaneUyesi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
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
 * Yapay zekâ yedeği: anahtar yoksa kapalı, yalnızca onayla ve yalnızca taslakta; kişisel veri dışarı gitmez; model
 * çıktısı şemaya ve satır satır doğrulanır (uydurma satır reddi); her hata türü ayrı ve ekstre bozulmaz.
 * Gerçek istek ATILMAZ (Http::fake).
 */
class YapayZekaEkstreTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Storage::fake('local');
        config(['services.openai.key' => 'test-anahtar', 'services.openai.model' => 'test-model']);
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

    private function yukle(): int
    {
        return $this->post('/api/v1/aile/ekstreler', ['kart_id' => $this->kart, 'dosya' => UploadedFile::fake()->createWithContent('e.pdf', $this->pdf())], ['Accept' => 'application/json'])
            ->assertCreated()->json('ekstre.id');
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
            'period' => ['start_date' => null, 'cut_date' => '2026-11-10', 'due_date' => '2026-11-20'],
            'summary' => ['previous_balance' => 0, 'total_due' => 2079.66, 'min_payment' => 700.00, 'total_purchases' => 2079.66, 'total_payments' => 0, 'total_fees_interest' => 0],
            'transactions' => $islemler, 'installments' => [], 'bonus_transactions' => [],
            'page_audit' => [['page' => 1, 'has_transaction_table' => true, 'found_transactions' => count($islemler)]],
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
            'date' => $tarih, 'description' => $aciklama, 'amount' => $tutar, 'currency' => 'TRY', 'transaction_type' => $tur, 'balance' => null,
            'source_page' => 1, 'source_text' => $kaynak, 'confidence' => 0.95,
            'installment_details' => ['number' => null, 'count' => null, 'total_amount' => null, 'remaining_amount' => null],
        ];
    }

    private function cevap(array $ai): PromiseInterface
    {
        return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($ai)]]]]]);
    }

    private function calistir(int $id, bool $onay = true): TestResponse
    {
        return $this->postJson("/api/v1/aile/ekstreler/{$id}/yapay-zeka", $onay ? ['onay' => true] : []);
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
        $id = $this->yukle();

        $this->calistir($id, onay: false)->assertJsonValidationErrors('onay');
        config(['services.openai.key' => '']);
        $this->calistir($id)->assertJsonValidationErrors('ekstre');
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertJsonPath('ai_acik', false);
        config(['services.openai.key' => 'test-anahtar']);
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertJsonPath('ai_acik', true);

        $ekstre = Ekstre::find($id);
        $ekstre->update(['durum' => 'onaylandi']);
        $this->calistir($id)->assertJsonValidationErrors('ekstre');
        Http::assertNothingSent();
    }

    public function test_basarili_calisma_dogrular_uydurma_satiri_reddeder_ve_kisisel_veri_gitmez(): void
    {
        Log::spy();
        Http::fake(['*' => $this->cevap($this->gecerli())]);
        $id = $this->yukle();
        $once = $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('ekstre');
        $this->assertSame('hesaplandi', $once['alan_kaynaklari']['asgari_odeme']);

        $this->calistir($id)->assertOk()->assertJsonPath('ozet.satir', 2)->assertJsonPath('ozet.reddedilen', 1)
            ->assertJsonPath('ekstre.banka_profili', 'ai')
            ->assertJsonPath('ekstre.asgari_odeme', 700)
            ->assertJsonPath('ekstre.alan_kaynaklari.asgari_odeme', 'ai')
            ->assertJsonPath('ekstre.denetim.ai.reddedilen.0.neden', 'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).')
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sayfa_uyusuyor', true)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sayfalar.0.ayristirilan_islem', 2)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sayfalar.0.inceleme_gereken', 1);

        $satirlar = collect($this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar'));
        $this->assertSame(['MIGROS KADIKOY', 'ENERJISA BASKENT EDAS'], $satirlar->pluck('aciklama')->all());
        $this->assertSame([1234.56, 845.1], $satirlar->pluck('tutar')->all());
        $this->assertSame(['taslak', 'taslak'], $satirlar->pluck('durum')->all()); // finans hareketi yazılmadı
        $this->assertStringContainsString('Yapay zekâ okudu', $satirlar[0]['okuma']['uyarilar'][0]);

        // İstek: Responses API, strict şema, kayıt yok, anahtar başlıkta; kişisel veri gitmedi
        Http::assertSent(function (IstemciIstegi $i): bool {
            $govde = $i->data();
            $metin = $govde['input'][1]['content'][0]['text'];

            return $i->hasHeader('Authorization', 'Bearer test-anahtar')
                && $govde['model'] === 'test-model' && $govde['store'] === false
                && $govde['text']['format']['type'] === 'json_schema' && $govde['text']['format']['strict'] === true
                && $govde['text']['format']['schema']['additionalProperties'] === false
                && str_contains($metin, '[SAYFA 1]') && str_contains($metin, 'MIGROS KADIKOY')
                && ! str_contains($metin, '4506') && ! str_contains($metin, 'ALI VELI');
        });

        // Çalışma kaydı içeriksiz
        $kayit = EkstreAiCalismasi::first();
        $this->assertSame(['basarili', 'test-model', 2, 1], [$kayit->durum, $kayit->model, $kayit->kabul_satir, $kayit->reddedilen_satir]);
        $this->assertEmpty(array_intersect(['metin', 'icerik', 'govde'], array_keys($kayit->getAttributes())));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');

        // Onay: yalnızca incelemeden sonra, mevcut akışla
        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 2);
    }

    public function test_hata_turleri_ayri_ele_alinir_ve_ekstre_bozulmaz(): void
    {
        $id = $this->yukle();
        $satirSayisi = fn (): int => count($this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar'));
        $onceki = $satirSayisi();

        $durumlar = [
            'hiz_siniri' => Http::response([], 429),
            'http_500' => Http::response(['hata' => 'GIZLI ICERIK'], 500),
            'http_401' => Http::response([], 401),
            'model_reddi' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'hayir']]]]]),
            'yarim' => Http::response(['status' => 'incomplete', 'output' => []]),
            'bozuk_json' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{bozuk']]]]]),
            'sema' => $this->cevap(['bank_name' => 'x']), // zorunlu alanlar yok
            'baglanti' => Http::failedConnection(),
        ];
        foreach ($durumlar as $kod => $yanit) {
            // Http::fake tekrar çağrılınca ilk sahte yanıt kalır; her tur için temiz fabrika
            Http::swap(new HttpFabrikasi);
            Http::fake(['*' => $yanit]);
            $cevap = $this->calistir($id)->assertStatus(422)->assertJsonValidationErrors('ekstre');
            $this->assertStringNotContainsString('GIZLI ICERIK', $cevap->getContent());
            $this->assertSame($kod, EkstreAiCalismasi::latest('id')->first()->hata_kodu);
            $this->assertSame('hata', EkstreAiCalismasi::latest('id')->first()->durum);
        }
        // Zaman aşımı
        Http::swap(new HttpFabrikasi);
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds')]);
        $this->calistir($id)->assertStatus(422);
        $this->assertSame('zaman_asimi', EkstreAiCalismasi::latest('id')->first()->hata_kodu);

        $this->assertSame($onceki, $satirSayisi());
        $this->assertSame('genel', Ekstre::find($id)->banka_profili);
    }

    public function test_sema_disi_alan_ve_yanlis_tur_reddedilir(): void
    {
        $id = $this->yukle();
        $fazla = $this->gecerli();
        $fazla['uydurma_alan'] = 1;
        Http::fake(['*' => $this->cevap($fazla)]);
        $this->calistir($id)->assertStatus(422);
        $this->assertSame('sema', EkstreAiCalismasi::latest('id')->first()->hata_kodu);

        $yanlis = $this->gecerli();
        $yanlis['transactions'][0]['amount'] = '1.234,56'; // sayı değil, metin
        Http::swap(new HttpFabrikasi);
        Http::fake(['*' => $this->cevap($yanlis)]);
        $this->calistir($id)->assertStatus(422);
        $this->assertSame('sema', EkstreAiCalismasi::latest('id')->first()->hata_kodu);
    }

    public function test_satir_dogrulama_kurallari(): void
    {
        $id = $this->yukle();
        $kotu = [
            $this->islem('2026-02-31', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'),       // tarih
            $this->islem('2026-10-15', 'MIGROS KADIKOY', 1.0, '15.10.2026 MIGROS KADIKOY 1.234,56'),            // tutar satırda yok
            [...$this->islem('2026-10-15', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'), 'currency' => 'USD'],
            [...$this->islem('2026-10-15', 'MIGROS KADIKOY', 1234.56, '15.10.2026 MIGROS KADIKOY 1.234,56'), 'source_page' => 9],
            $this->islem('2026-10-16', 'ENERJISA BASKENT EDAS', 845.10, '16.10.2026 ENERJISA BASKENT EDAS 845,10', 'refund'), // geçerli (iade)
        ];
        Http::fake(['*' => $this->cevap($this->ai($kotu))]);

        $cevap = $this->calistir($id)->assertOk()->assertJsonPath('ozet.satir', 1)->assertJsonPath('ozet.reddedilen', 4);
        $nedenler = array_column($cevap->json('ekstre.denetim.ai.reddedilen'), 'neden');
        $this->assertSame(['Tarih geçersiz.', 'Tutar, kaynak satırda yazmıyor.', 'Para birimi TL değil.', 'Kaynak sayfa geçersiz.'], $nedenler);
        $this->assertSame('iade', $this->getJson("/api/v1/aile/ekstreler/{$id}")->json('satirlar.0.tur'));
    }

    public function test_baska_hane_yapay_zekayi_calistiramaz(): void
    {
        Http::fake();
        $id = $this->yukle();
        $this->actingAs(User::factory()->create());
        $this->calistir($id)->assertForbidden();
        Http::assertNothingSent();
    }
}
