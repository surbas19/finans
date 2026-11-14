<?php

namespace Tests\Feature;

use App\Models\HesapEkstresi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrnekPdf;
use Tests\TestCase;

/**
 * Banka hesabı ekstresi: yalnızca yapay zekâ analizi ve önizleme (Bütçe'ye aktarılmaz), açık onayla; PDF analizden
 * sonra silinir, maskeli JSON kalır; aynı dosya bir kez; hane izolasyonu. Gerçek istek atılmaz.
 */
class HesapEkstresiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = 'https://api.openai.com/v1/responses';

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Storage::fake('local');
        config(['services.openai.key' => 'test-anahtar', 'services.openai.url' => self::ADRES, 'services.openai.arka_plan' => true]);
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
    }

    private function pdf(): string
    {
        return OrnekPdf::yaz([[
            'ORNEK BANKA HESAP EKSTRESI',
            [40 => 'Musteri No', 200 => '12345678'],
            [40 => '01.10.2026', 110 => 'ACILIS BAKIYESI', 450 => '1.000,00'],
            [40 => '05.10.2026', 110 => 'MAAS', 380 => '+50.000,00', 450 => '51.000,00'],
            [40 => '06.10.2026', 110 => 'KIRA HAVALE', 380 => '-15.000,00', 450 => '36.000,00'],
        ]]);
    }

    private function ai(): array
    {
        $ozet = array_fill_keys(['previous_balance', 'total_due', 'min_payment', 'total_purchases', 'total_payments', 'total_refunds', 'total_interest', 'total_fees', 'total_taxes', 'total_bonus_used', 'remaining_installments'], null);
        $islem = fn (string $tarih, string $aciklama, float $tutar, string $yon, string $tur, float $bakiye, string $kaynak): array => [
            'date' => $tarih, 'description' => $aciklama, 'amount' => $tutar, 'currency' => 'TRY', 'direction' => $yon, 'transaction_type' => $tur,
            'original_currency' => null, 'original_amount' => null, 'balance' => $bakiye, 'bonus_earned' => null, 'source_page' => 1,
            'source_text' => $kaynak, 'confidence' => 0.9, 'needs_review' => false, 'review_reason' => null,
            'installment_details' => ['number' => null, 'count' => null, 'total_amount' => null, 'remaining_amount' => null],
        ];

        return [
            'bank_name' => 'Ornek Banka', 'statement_type' => 'bank_account', 'account' => ['label' => 'Vadesiz TL 4506 34** **** 4589', 'currency' => 'TRY'],
            'period' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'cut_date' => null, 'due_date' => null],
            'summary' => [...$ozet, 'opening_balance' => 1000, 'closing_balance' => 36000, 'total_credits' => 50000, 'total_debits' => 15000],
            'transactions' => [
                $islem('2026-10-05', 'MAAS', 50000, 'credit', 'deposit', 51000, '05.10.2026 MAAS +50.000,00 51.000,00'),
                $islem('2026-10-06', 'KIRA HAVALE', 15000, 'debit', 'transfer', 36000, '06.10.2026 KIRA HAVALE -15.000,00 36.000,00'),
            ],
            'installments' => [], 'bonus_transactions' => [],
            'page_audit' => [['page_number' => 1, 'has_transaction_table' => true, 'found_count' => 2, 'status' => 'processed']],
            'warnings' => [], 'review_required' => false,
        ];
    }

    private function yukle(bool $onay = true): TestResponse
    {
        return $this->post('/api/v1/aile/hesap-ekstreleri', ['dosya' => UploadedFile::fake()->createWithContent('hesap.pdf', $this->pdf()), ...($onay ? ['onay' => '1'] : [])], ['Accept' => 'application/json']);
    }

    public function test_onizleme_maskeli_json_pdf_silinir_tekrar_yok(): void
    {
        Http::fake([
            self::ADRES => Http::response(['id' => 'resp_h', 'status' => 'queued']),
            self::ADRES.'/resp_h' => Http::response(['id' => 'resp_h', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->ai())]]]]]),
        ]);
        $this->yukle(onay: false)->assertJsonValidationErrors('onay');
        config(['services.openai.key' => '']);
        $this->yukle()->assertJsonValidationErrors('dosya');
        config(['services.openai.key' => 'test-anahtar']);
        Http::assertNothingSent();

        $id = $this->yukle()->assertCreated()->assertJsonPath('calisma.durum', 'bekliyor')->assertJsonPath('hesap_ekstresi.durum', 'analiz')->json('hesap_ekstresi.id');
        $yol = HesapEkstresi::find($id)->dosya_yolu;
        Storage::disk('local')->assertExists($yol);
        $this->assertStringStartsWith('hesap-ekstreleri/', $yol);

        Carbon::setTestNow(now()->addSeconds(6));
        $this->getJson("/api/v1/aile/hesap-ekstreleri/{$id}")->assertOk()
            ->assertJsonPath('calisma.durum', 'basarili')
            ->assertJsonPath('hesap_ekstresi.durum', 'onizleme')
            ->assertJsonPath('hesap_ekstresi.banka_adi', 'Ornek Banka')
            ->assertJsonPath('hesap_ekstresi.acilis_bakiyesi', 1000)
            ->assertJsonPath('hesap_ekstresi.kapanis_bakiyesi', 36000)
            ->assertJsonPath('ai_cikti.transactions.1.transaction_type', 'transfer')
            ->assertJsonPath('ai_cikti.account.label', 'Vadesiz TL [KART]'); // maskeli
        // PDF yalnızca analiz süresince
        Storage::disk('local')->assertMissing($yol);
        $this->assertNull(HesapEkstresi::find($id)->dosya_yolu);

        $this->yukle()->assertJsonValidationErrors('dosya');
        $this->getJson('/api/v1/aile/hesap-ekstreleri')->assertJsonCount(1, 'hesap_ekstreleri');
        $this->deleteJson("/api/v1/aile/hesap-ekstreleri/{$id}")->assertOk();
        $this->assertSame(0, HesapEkstresi::count());
    }

    public function test_baska_hane_goremez_silemez(): void
    {
        Http::fake(['*' => Http::response(['id' => 'r', 'status' => 'queued'])]);
        $id = $this->yukle()->assertCreated()->json('hesap_ekstresi.id');
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/aile/hesap-ekstreleri')->assertJsonCount(0, 'hesap_ekstreleri');
        $this->getJson("/api/v1/aile/hesap-ekstreleri/{$id}")->assertForbidden();
        $this->deleteJson("/api/v1/aile/hesap-ekstreleri/{$id}")->assertForbidden();
        $this->assertSame(1, HesapEkstresi::count());
    }
}
