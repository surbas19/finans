<?php

namespace App\Services\AIStatement;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API istemcisi: PDF dosyası `input_file` (base64; Files API kullanılmaz, dosya OpenAI'de saklanmaz)
 * + sunucunun okuduğu temizlenmiş sayfa metni, Structured Outputs (json_schema, strict), `store: false`.
 * İki yol: arka plan modu (`background: true`; oluştur → yanıt kimliğiyle sor → iptal) ve senkron (cron'da, süre
 * sınırı yok). Anahtar yalnızca sunucuda; istek / yanıt gövdesi, anahtar ve ekstre içeriği hiçbir yerde loglanmaz.
 * Hata türleri ayrı: bağlantı, zaman aşımı, HTTP kodu, hız sınırı, model reddi, yarım yanıt, bozuk JSON, arka plan
 * modu desteklenmiyor, dosya büyük.
 */
class OpenAIClient
{
    /** Son yanıtın token kullanımı (yalnızca sayılar; maliyet izlemek için) */
    public ?array $sonKullanim = null;

    public function acikMi(): bool
    {
        return trim((string) config('services.openai.key')) !== '';
    }

    /**
     * İstek gövdesi. PDF hem görüntü hem metin olarak okunur (`detail` boşsa modelin varsayılanı; gpt-4.1'de "auto"
     * düşük çözünürlüktür, bu yüzden varsayılan "high").
     *
     * @param  array<string, mixed>  $sema  JSON Schema (strict)
     * @return array<string, mixed>
     *
     * @throws YapayZekaHatasi
     */
    public function govde(string $istem, string $pdf, string $dosyaAdi, string $sayfaMetni, array $sema, bool $arkaPlan): array
    {
        if (strlen($pdf) > (int) config('ekstre.ai_max_mb') * 1024 * 1024) {
            throw YapayZekaHatasi::dosyaBuyuk((int) config('ekstre.ai_max_mb'));
        }
        $dosya = ['type' => 'input_file', 'filename' => $dosyaAdi, 'file_data' => 'data:application/pdf;base64,'.base64_encode($pdf)];
        $detay = trim((string) config('services.openai.pdf_detail'));
        if ($detay !== '') {
            $dosya['detail'] = $detay;
        }

        return [
            'model' => (string) config('services.openai.model'),
            'store' => false,
            ...($arkaPlan ? ['background' => true] : []),
            'max_output_tokens' => (int) config('services.openai.max_output_tokens'),
            'input' => [
                ['role' => 'system', 'content' => $istem],
                ['role' => 'user', 'content' => [
                    $dosya,
                    ['type' => 'input_text', 'text' => "Sunucunun PDF'ten okuduğu sayfa metni (source_text bundan aynen kopyalanır):\n\n".$sayfaMetni],
                ]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'bank_statement', 'strict' => true, 'schema' => $sema]],
        ];
    }

    /**
     * Senkron çağrı: yanıt gelene kadar bekler (cron yolu; web isteğinde kullanılmaz).
     *
     * @param  array<string, mixed>  $govde
     * @return array<string, mixed> çözülmüş JSON
     *
     * @throws YapayZekaHatasi
     */
    public function yapilandirilmisCevap(array $govde): array
    {
        unset($govde['background']);
        $veri = $this->json($this->istek(fn () => $this->http()->post($this->adres(), $govde)));
        $durum = $this->durum($veri);
        if ($durum['sonuc'] === null) {
            throw YapayZekaHatasi::bozukJson();
        }

        return $durum['sonuc'];
    }

    /**
     * Arka plan modunda oluşturur; çoğunlukla hemen döner (queued / in_progress).
     *
     * @param  array<string, mixed>  $govde
     * @return array{id: string, durum: string, sonuc: array<string, mixed>|null}
     *
     * @throws YapayZekaHatasi
     */
    public function baslat(array $govde): array
    {
        $cevap = $this->istek(fn () => $this->http()->post($this->adres(), [...$govde, 'background' => true]), arkaPlan: true);
        $veri = $this->json($cevap);
        if (! is_string($veri['id'] ?? null) || $veri['id'] === '') {
            throw YapayZekaHatasi::bozukJson();
        }

        return ['id' => $veri['id'], ...$this->durum($veri)];
    }

    /**
     * Arka plandaki yanıtın durumu; tamamlandıysa çözülmüş JSON.
     *
     * @return array{durum: string, sonuc: array<string, mixed>|null}
     *
     * @throws YapayZekaHatasi
     */
    public function sor(string $id): array
    {
        return $this->durum($this->json($this->istek(fn () => $this->http()->get($this->adres().'/'.rawurlencode($id)))));
    }

    /** İptal (sonucu artık istenmeyen çalışma); hata yutulur, iptal en iyi çabadır */
    public function iptal(string $id): void
    {
        try {
            $this->http()->post($this->adres().'/'.rawurlencode($id).'/cancel');
        } catch (\Throwable) {
            // içerik / hata loglanmaz
        }
    }

    /**
     * @param  array<string, mixed>  $veri
     * @return array{durum: string, sonuc: array<string, mixed>|null}
     *
     * @throws YapayZekaHatasi
     */
    private function durum(array $veri): array
    {
        $durum = (string) ($veri['status'] ?? 'completed');
        $this->sonKullanim = isset($veri['usage']) ? [
            'girdi' => (int) ($veri['usage']['input_tokens'] ?? 0),
            'cikti' => (int) ($veri['usage']['output_tokens'] ?? 0),
        ] : $this->sonKullanim;

        return match ($durum) {
            'queued', 'in_progress' => ['durum' => $durum, 'sonuc' => null],
            'completed' => ['durum' => 'completed', 'sonuc' => $this->ciktiMetniniCoz($veri)],
            'incomplete' => throw YapayZekaHatasi::yarimKaldi((string) ($veri['incomplete_details']['reason'] ?? '')),
            'cancelled' => throw YapayZekaHatasi::iptal(),
            'failed' => throw YapayZekaHatasi::modelHatasi(),
            default => throw YapayZekaHatasi::bozukJson(),
        };
    }

    /**
     * @param  callable(): Response  $istek
     *
     * @throws YapayZekaHatasi
     */
    private function istek(callable $istek, bool $arkaPlan = false): Response
    {
        if (! $this->acikMi()) {
            throw YapayZekaHatasi::kapali();
        }
        try {
            $cevap = $istek();
        } catch (ConnectionException $e) {
            throw str_contains(strtolower($e->getMessage()), 'timed out') ? YapayZekaHatasi::zamanAsimi() : YapayZekaHatasi::baglanti();
        } catch (RequestException) {
            throw YapayZekaHatasi::baglanti();
        }
        if ($cevap->status() === 429) {
            throw YapayZekaHatasi::hizSiniri();
        }
        // Arka plan modu bu ayarla (store:false / model) kabul edilmezse çalışma senkron yola (cron) geçer
        if ($arkaPlan && $cevap->status() === 400 && preg_match('/background|store/i', (string) ($cevap->json('error.message') ?? ''))) {
            throw YapayZekaHatasi::arkaPlanYok();
        }
        if ($cevap->failed()) {
            throw YapayZekaHatasi::http($cevap->status());
        }

        return $cevap;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws YapayZekaHatasi
     */
    private function json(Response $cevap): array
    {
        $veri = $cevap->json();
        if (! is_array($veri)) {
            throw YapayZekaHatasi::bozukJson();
        }

        return $veri;
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.openai.key'))
            ->acceptJson()->asJson()
            ->connectTimeout(15)->timeout((int) config('services.openai.timeout'));
    }

    private function adres(): string
    {
        return rtrim((string) config('services.openai.url'), '/');
    }

    /**
     * Yalnızca geçerli `output_text` alınır (redler ve başka çıktı türleri atlanır).
     *
     * @param  array<string, mixed>  $veri
     * @return array<string, mixed>
     *
     * @throws YapayZekaHatasi
     */
    private function ciktiMetniniCoz(array $veri): array
    {
        $metin = null;
        foreach ($veri['output'] ?? [] as $oge) {
            if (($oge['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($oge['content'] ?? [] as $parca) {
                if (($parca['type'] ?? null) === 'refusal') {
                    throw YapayZekaHatasi::reddedildi();
                }
                if (($parca['type'] ?? null) === 'output_text' && is_string($parca['text'] ?? null)) {
                    $metin = $parca['text'];
                }
            }
        }
        if ($metin === null) {
            throw YapayZekaHatasi::bozukJson();
        }
        $cozulen = json_decode($metin, true);
        if (! is_array($cozulen)) {
            throw YapayZekaHatasi::bozukJson();
        }

        return $cozulen;
    }
}
