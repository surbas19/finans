<?php

namespace App\Services\AIStatement;

use App\Models\Ekstre;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Services\Ekstre\EkstreOkunamadi;
use App\Services\Ekstre\MetinNormalizer;
use App\Services\Ekstre\PdfMetinOkuyucu;
use Illuminate\Support\Facades\Storage;

/**
 * Yapay zekâ ile ekstre analizi, iki adım:
 * - `hazirla`: PDF'in sunucuda okunan metni normalize edilir, kişisel satırları çıkarılır, "[SAYFA n]" başlıklarıyla
 *   birleştirilir; istek gövdesi PDF dosyası (input_file) + bu metin + strict şemadır.
 * - `coz`: dönen JSON şemaya ve satır satır doğrulanır (kaynak satır o sayfada mı, tutar satırda mı), sayfa denetimi
 *   yapılır. Hiçbir şeyi kaydetmez (kayıt StatementImporter'da; aktarım kullanıcı onayıyla).
 * Arka plan çalışmasında iki adım ayrı isteklerde yürür; senkron yolda `analiz` ikisini birden yapar.
 */
class StatementAnalyzer
{
    /** OpenAI'ye giden dosya adı: yüklenen adın içinde kişi adı olabilir */
    public const DOSYA_ADI = 'ekstre.pdf';

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
     * @return array{govde: array<string, mixed>, sayfa_metinleri: list<string>, sayfa_sayisi: int, metinsiz: bool}
     *
     * @throws YapayZekaHatasi
     */
    public function hazirla(string $pdf, bool $arkaPlan): array
    {
        try {
            $okunan = $this->okuyucu->oku($pdf, metinsizKabul: true);
        } catch (EkstreOkunamadi) {
            throw YapayZekaHatasi::metinYok();
        }
        $sayfaMetinleri = array_map(
            fn (array $sayfa): string => implode("\n", $this->temizleyici->temizle($this->normalizer->normalize([$sayfa])[0])),
            $okunan['sayfalar'],
        );
        $gonderilen = '';
        foreach ($sayfaMetinleri as $i => $metin) {
            $gonderilen .= '[SAYFA '.($i + 1)."]\n".$metin."\n\n";
        }

        return [
            'govde' => $this->istemci->govde($this->istem(), $pdf, self::DOSYA_ADI, $gonderilen, $this->sema(), $arkaPlan),
            'sayfa_metinleri' => $sayfaMetinleri,
            'sayfa_sayisi' => $okunan['sayfa_sayisi'],
            'metinsiz' => $okunan['metinsiz'],
        ];
    }

    /**
     * @param  array<string, mixed>  $veri  modelin JSON'u
     * @param  list<string>  $sayfaMetinleri
     * @return array{ai: array<string, mixed>, kabul: list<array<string, mixed>>, reddedilen: list<array<string, mixed>>, sayfa_denetimi: array<string, mixed>, model: string}
     *
     * @throws YapayZekaHatasi
     */
    public function coz(array $veri, array $sayfaMetinleri, int $sayfaSayisi): array
    {
        if ($this->jsonDogrulayici->dogrula($veri, $this->sema()) !== []) {
            throw YapayZekaHatasi::semaHatasi();
        }
        ['kabul' => $kabul, 'reddedilen' => $reddedilen] = $this->islemDogrulayici->dogrula($veri['transactions'], $sayfaMetinleri);

        return [
            'ai' => $veri,
            'kabul' => $kabul,
            'reddedilen' => $reddedilen,
            'sayfa_denetimi' => $this->denetim->denetle($sayfaSayisi, $veri['page_audit'], $kabul, $reddedilen),
            'model' => (string) config('services.openai.model'),
        ];
    }

    /**
     * Senkron analiz (cron yolu): hazırla → yanıtı bekle → çöz.
     *
     * @return array{ai: array<string, mixed>, kabul: list<array<string, mixed>>, reddedilen: list<array<string, mixed>>, sayfa_denetimi: array<string, mixed>, model: string}
     *
     * @throws YapayZekaHatasi
     */
    public function analiz(Ekstre $ekstre): array
    {
        $hazir = $this->hazirla($this->pdf($ekstre->dosya_yolu), arkaPlan: false);

        return $this->coz($this->istemci->yapilandirilmisCevap($hazir['govde']), $hazir['sayfa_metinleri'], $hazir['sayfa_sayisi']);
    }

    /**
     * Saklı PDF'in içeriği.
     *
     * @throws YapayZekaHatasi
     */
    public function pdf(?string $yol): string
    {
        if (! $this->istemci->acikMi()) {
            throw YapayZekaHatasi::kapali();
        }
        $disk = Storage::disk(EkstreIceAktarici::DISK);
        if ($yol === null || ! $disk->exists($yol)) {
            throw YapayZekaHatasi::metinYok();
        }

        return (string) $disk->get($yol);
    }

    /**
     * @return array<string, mixed>
     */
    private function sema(): array
    {
        return json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function istem(): string
    {
        return (string) file_get_contents(app_path('Prompts/statement_extraction.txt'));
    }
}
