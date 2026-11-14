<?php

namespace Tests\Feature;

use App\Services\AIStatement\OpenAIClient;
use App\Services\AIStatement\YapayZekaHatasi;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as IstemciIstegi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * OpenAI Responses API istemcisi: PDF input_file gövdesi, arka plan modu (oluştur → sor → iptal), durum ve hata türleri.
 * Gerçek istek atılmaz.
 */
class OpenAIIstemcisiTest extends TestCase
{
    private const ADRES = 'https://openai.test/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.key' => 'test-anahtar', 'services.openai.model' => 'test-model', 'services.openai.url' => self::ADRES,
            'services.openai.pdf_detail' => 'high', 'services.openai.max_output_tokens' => 32000, 'ekstre.ai_max_mb' => 1,
        ]);
    }

    private function govde(bool $arkaPlan = true): array
    {
        return app(OpenAIClient::class)->govde('istem', "%PDF-1.4\n...", 'ekstre.pdf', '[SAYFA 1] ...', ['type' => 'object'], $arkaPlan);
    }

    private static function tamam(array $json, string $id = 'resp_1'): array
    {
        return ['id' => $id, 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]]];
    }

    private function hata(callable $is): string
    {
        try {
            $is();
        } catch (YapayZekaHatasi $e) {
            return $e->kod;
        }
        $this->fail('Hata bekleniyordu.');
    }

    public function test_govde_pdf_input_file_detail_ve_buyuk_dosya(): void
    {
        $g = $this->govde();
        $this->assertSame([true, false, 32000], [$g['background'], $g['store'], $g['max_output_tokens']]);
        $this->assertSame(['type' => 'input_file', 'filename' => 'ekstre.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode("%PDF-1.4\n..."), 'detail' => 'high'], $g['input'][1]['content'][0]);
        $this->assertSame('input_text', $g['input'][1]['content'][1]['type']);
        // detail boşsa gönderilmez; arka plan istenmezse background yok
        config(['services.openai.pdf_detail' => '']);
        $g = $this->govde(false);
        $this->assertArrayNotHasKey('detail', $g['input'][1]['content'][0]);
        $this->assertArrayNotHasKey('background', $g);
        // Sınırı aşan PDF gönderilmez
        $this->assertSame('dosya_buyuk', $this->hata(fn () => app(OpenAIClient::class)->govde('i', str_repeat('x', 1024 * 1024 + 1), 'ekstre.pdf', '', [], true)));
    }

    public function test_arka_plan_olustur_sor_iptal(): void
    {
        Log::spy();
        Http::fake([
            self::ADRES => Http::response(['id' => 'resp_9', 'status' => 'queued']),
            self::ADRES.'/resp_9' => Http::sequence()
                ->push(['id' => 'resp_9', 'status' => 'in_progress'])
                ->push(self::tamam(['tamam' => true], 'resp_9')),
            self::ADRES.'/resp_9/cancel' => Http::response(['id' => 'resp_9', 'status' => 'cancelled']),
        ]);
        $istemci = app(OpenAIClient::class);

        $this->assertSame(['id' => 'resp_9', 'durum' => 'queued', 'sonuc' => null], $istemci->baslat($this->govde()));
        $this->assertSame(['durum' => 'in_progress', 'sonuc' => null], $istemci->sor('resp_9'));
        $this->assertSame(['durum' => 'completed', 'sonuc' => ['tamam' => true]], $istemci->sor('resp_9'));
        $istemci->iptal('resp_9');

        Http::assertSent(fn (IstemciIstegi $i): bool => $i->url() === self::ADRES && $i['background'] === true && $i['store'] === false && $i->hasHeader('Authorization', 'Bearer test-anahtar'));
        Http::assertSent(fn (IstemciIstegi $i): bool => $i->method() === 'GET' && $i->url() === self::ADRES.'/resp_9');
        Http::assertSent(fn (IstemciIstegi $i): bool => $i->method() === 'POST' && $i->url() === self::ADRES.'/resp_9/cancel');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    public function test_durum_ve_hata_turleri(): void
    {
        $istemci = app(OpenAIClient::class);
        $durumlar = [
            [['id' => 'r', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']], 'yarim'],
            [['id' => 'r', 'status' => 'failed', 'error' => ['message' => 'x']], 'model_hatasi'],
            [['id' => 'r', 'status' => 'cancelled'], 'iptal'],
            [['id' => 'r', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'hayır']]]]], 'model_reddi'],
            [['id' => 'r', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{bozuk']]]]], 'bozuk_json'],
        ];
        foreach ($durumlar as [$yanit, $kod]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::ADRES.'/r' => Http::response($yanit)]);
            $this->assertSame($kod, $this->hata(fn () => $istemci->sor('r')), $kod);
        }
        $yarim = null;
        try {
            Http::swap(new Factory);
            Http::fake([self::ADRES.'/r' => Http::response($durumlar[0][0])]);
            $istemci->sor('r');
        } catch (YapayZekaHatasi $e) {
            $yarim = $e->getMessage();
        }
        $this->assertStringContainsString('OPENAI_MAX_OUTPUT_TOKENS', (string) $yarim);

        // Arka plan modu kabul edilmezse ayrı kod (çalışma senkron yola geçer); diğer 400'ler HTTP hatası
        $kodlar = [
            [Http::response(['error' => ['message' => 'Background mode requires store=true']], 400), 'arka_plan_yok'],
            [Http::response(['error' => ['message' => 'Invalid value for detail']], 400), 'http_400'],
            [Http::response(['error' => ['message' => 'Rate limit']], 429), 'hiz_siniri'],
            [Http::response([], 401), 'http_401'],
            [Http::response('GIZLI ICERIK', 500), 'http_500'],
        ];
        foreach ($kodlar as [$yanit, $kod]) {
            Http::swap(new Factory);
            Http::fake([self::ADRES => $yanit]);
            $this->assertSame($kod, $this->hata(fn () => $istemci->baslat($this->govde())), $kod);
        }

        // Anahtar yoksa istek atılmaz
        config(['services.openai.key' => '']);
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame('kapali', $this->hata(fn () => $istemci->sor('r')));
        Http::assertNothingSent();
    }
}
