<?php

namespace App\Services\AIStatement;

use App\Models\Ekstre;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Services\Ekstre\EkstreOkunamadi;
use App\Services\Ekstre\MetinNormalizer;
use App\Services\Ekstre\PdfMetinOkuyucu;
use Illuminate\Support\Facades\Storage;

/**
 * Yapay zekâ yedeği analizi: saklı PDF'in metnini okur, normalize eder, kişisel veriyi çıkarır, "[SAYFA n]" başlıklı
 * metni modele şema ile gönderir, dönen JSON'u şemaya ve satır satır doğrulatır, sayfa denetimini yapar. Hiçbir şeyi
 * kaydetmez (kayıt `StatementImporter`'da, kullanıcı onayından sonra). PDF'in kendisi değil temizlenmiş metin gider.
 */
class StatementAnalyzer
{
    public function __construct(
        private PdfMetinOkuyucu $okuyucu,
        private MetinNormalizer $normalizer,
        private KisiselVeriTemizleyici $temizleyici,
        private OpenAIClient $istemci,
        private JsonValidator $jsonDogrulayici,
        private TransactionValidator $islemDogrulayici,
        private StatementAuditService $denetim,
    ) {}

    /**
     * @return array{ai: array<string, mixed>, kabul: list<array<string, mixed>>, reddedilen: list<array<string, mixed>>, sayfa_denetimi: array<string, mixed>, model: string}
     *
     * @throws YapayZekaHatasi
     */
    public function analiz(Ekstre $ekstre): array
    {
        if (! $this->istemci->acikMi()) {
            throw YapayZekaHatasi::kapali();
        }
        $disk = Storage::disk(EkstreIceAktarici::DISK);
        if ($ekstre->dosya_yolu === null || ! $disk->exists($ekstre->dosya_yolu)) {
            throw YapayZekaHatasi::metinYok();
        }
        try {
            $sayfalar = $this->okuyucu->oku((string) $disk->get($ekstre->dosya_yolu))['sayfalar'];
        } catch (EkstreOkunamadi) {
            throw YapayZekaHatasi::metinYok();
        }

        $temiz = array_map(fn (array $sayfa): array => $this->temizleyici->temizle($this->normalizer->normalize([$sayfa])[0]), $sayfalar);
        $sayfaMetinleri = array_map(fn (array $s): string => implode("\n", $s), $temiz);
        $gonderilen = '';
        foreach ($sayfaMetinleri as $i => $metin) {
            $gonderilen .= '[SAYFA '.($i + 1)."]\n".$metin."\n\n";
        }

        $sema = json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true);
        $istem = (string) file_get_contents(app_path('Prompts/statement_extraction.txt'));
        $veri = $this->istemci->yapilandirilmisCevap($istem, $gonderilen, $sema);
        if ($this->jsonDogrulayici->dogrula($veri, $sema) !== []) {
            throw YapayZekaHatasi::semaHatasi();
        }

        ['kabul' => $kabul, 'reddedilen' => $reddedilen] = $this->islemDogrulayici->dogrula($veri['transactions'], $sayfaMetinleri);

        return [
            'ai' => $veri,
            'kabul' => $kabul,
            'reddedilen' => $reddedilen,
            'sayfa_denetimi' => $this->denetim->denetle(count($sayfalar), $veri['page_audit'], $kabul, $reddedilen),
            'model' => (string) config('services.openai.model'),
        ];
    }
}
