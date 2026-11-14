<?php

namespace App\Services\AIStatement;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API istemcisi (Structured Outputs: json_schema, strict). Anahtar yalnızca sunucuda; istek / yanıt
 * gövdesi, anahtar ve ekstre içeriği hiçbir yerde loglanmaz. `store: false` (OpenAI tarafında saklama istenmez).
 * Hata türleri ayrı ele alınır: bağlantı, zaman aşımı, HTTP kodu, hız sınırı, model reddi, yarım yanıt, bozuk JSON.
 */
class OpenAIClient
{
    public function acikMi(): bool
    {
        return trim((string) config('services.openai.key')) !== '';
    }

    /**
     * @param  array<string, mixed>  $sema  JSON Schema (strict)
     * @return array<string, mixed> çözülmüş JSON
     *
     * @throws YapayZekaHatasi
     */
    public function yapilandirilmisCevap(string $sistemIstemi, string $kullaniciMetni, array $sema): array
    {
        if (! $this->acikMi()) {
            throw YapayZekaHatasi::kapali();
        }
        $govde = [
            'model' => (string) config('services.openai.model'),
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => $sistemIstemi],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $kullaniciMetni]]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'bank_statement', 'strict' => true, 'schema' => $sema]],
        ];

        try {
            $cevap = Http::withToken((string) config('services.openai.key'))
                ->acceptJson()->asJson()
                ->connectTimeout(15)->timeout((int) config('services.openai.timeout'))
                ->post((string) config('services.openai.url'), $govde);
        } catch (ConnectionException $e) {
            throw str_contains(strtolower($e->getMessage()), 'timed out') ? YapayZekaHatasi::zamanAsimi() : YapayZekaHatasi::baglanti();
        } catch (RequestException) {
            throw YapayZekaHatasi::baglanti();
        }

        if ($cevap->status() === 429) {
            throw YapayZekaHatasi::hizSiniri();
        }
        if ($cevap->failed()) {
            throw YapayZekaHatasi::http($cevap->status());
        }

        $veri = $cevap->json();
        if (! is_array($veri)) {
            throw YapayZekaHatasi::bozukJson();
        }
        if (($veri['status'] ?? 'completed') === 'incomplete') {
            throw YapayZekaHatasi::yarimKaldi();
        }

        return $this->ciktiMetniniCoz($veri);
    }

    /**
     * Yalnızca geçerli `output_text` alınır (redler ve başka çıktı türleri atlanır).
     *
     * @param  array<string, mixed>  $veri
     * @return array<string, mixed>
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
