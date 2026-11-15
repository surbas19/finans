<?php

namespace App\Services\AIStatement;

/**
 * Kullanıcının PDF'i kendi seçtiği sohbet yapay zekâsına (ChatGPT, Claude, Gemini…) yükleyip yapıştıracağı istem.
 * Metin `Prompts/statement_extraction_external.txt`; şema ve boş örnek şablon `Schemas/bank_statement_schema.json`'dan
 * dinamik birleştirilir (şemanın tek kaynağı bu dosya: sunucudaki OpenAI yolu ve bu istem aynı şemayı kullanır).
 * İstemde kişisel veri ya da kullanıcıya özgü bilgi yoktur.
 */
class HariciIstem
{
    /** Şema sürümü (standart JSON v2); istem ve JSON birlikte değişirse artar */
    public const SEMA_SURUMU = 'v2';

    public function olustur(): string
    {
        $sema = $this->sema();

        return strtr((string) file_get_contents(app_path('Prompts/statement_extraction_external.txt')), [
            '{{SEMA}}' => json_encode($sema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '{{ORNEK}}' => json_encode($this->ornek($sema), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sema(): array
    {
        return json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Şemadan boş örnek: boş olabilen alan null, metin "", sayı 0, mantıksal false, sıralı alan ilk değeri; liste
     * türünde tek örnek öğe (yalnızca işlem listesinde; diğer listeler boş).
     *
     * @param  array<string, mixed>  $sema
     */
    public function ornek(?array $sema = null, string $anahtar = ''): mixed
    {
        $sema ??= $this->sema();
        $turler = (array) ($sema['type'] ?? []);
        if (in_array('null', $turler, true)) {
            return null;
        }
        if (isset($sema['enum'])) {
            return $sema['enum'][0];
        }

        return match ($turler[0] ?? null) {
            'object' => collect($sema['properties'] ?? [])->map(fn (array $alt, string $ad): mixed => $this->ornek($alt, $ad))->all(),
            'array' => $anahtar === 'transactions' && isset($sema['items']) ? [$this->ornek($sema['items'])] : [],
            'string' => '',
            'number', 'integer' => 0,
            'boolean' => false,
            default => null,
        };
    }
}
