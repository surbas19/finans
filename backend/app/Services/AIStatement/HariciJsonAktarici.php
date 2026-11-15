<?php

namespace App\Services\AIStatement;

use App\Enums\KartIslemDurumu;
use App\Models\Ekstre;
use App\Models\EkstreAiCalismasi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Support\Kurus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dış yapay zekâ JSON'undan taslak kart ekstresi: kullanıcı PDF'i kendi seçtiği bir sohbet yapay zekâsına (ChatGPT,
 * Claude, Gemini…) kendisi yükler, HariciIstem'le standart JSON'u (şema v2) ürettirir ve buraya yapıştırır. Sunucu
 * hiçbir dış servise istek atmaz.
 *
 * - Çit (```) ve BOM soyulur; başka düzeltme / tahmin yoktur. Şemaya uymayan JSON hata yollarıyla reddedilir.
 * - PDF isteğe bağlı: verilirse sunucu sayfa metnini okur ve kaynak satırlar o metinde doğrulanır (OpenAI'ye gitmez);
 *   verilmezse "metinsiz kaynak" modu: satır satır işaret yok, ekstre düzeyinde tek uyarı; borç denklemi / özet
 *   toplamları tutmuyorsa tüm satırlar inceleme ister.
 * - Sonuç her zaman taslaktır (StatementImporter); onay mevcut "Kontrol ettim" kapısından geçer.
 * - JSON, satırlar ve tutarlar loglanmaz; saklanan çıktı maskelenir; çalışma kaydı içeriksizdir.
 */
class HariciJsonAktarici
{
    /** Yapıştırılabilecek en büyük JSON (bayt) */
    public const MAX_BAYT = 2 * 1024 * 1024;

    /** Kullanıcıya gösterilen en çok hata sayısı */
    private const MAX_HATA = 20;

    public function __construct(
        private EkstreIceAktarici $aktarici,
        private StatementImporter $importer,
        private JsonValidator $jsonDogrulayici,
        private TransactionValidator $islemDogrulayici,
        private StatementAnalyzer $analizci,
        private KisiselVeriTemizleyici $temizleyici,
        private PdfUploadService $yukleme,
    ) {}

    /**
     * @return array{ekstre: Ekstre, ozet: array{satir: int, inceleme: int, reddedilen: int}, uyarilar: list<string>}
     *
     * @throws ValidationException json / dosya alanında hata listesiyle
     */
    public function taslakOlustur(User $user, Kart $kart, string $json, ?UploadedFile $pdf = null): array
    {
        if (strlen($json) > self::MAX_BAYT) {
            throw ValidationException::withMessages(['json' => 'JSON en çok '.(self::MAX_BAYT / 1024 / 1024).' MB olabilir.']);
        }
        $veri = $this->coz($json);
        $this->semayaUydur($veri);

        $pdfBilgisi = $pdf !== null ? $this->yukleme->oku($pdf) : null;
        $ozet = $pdfBilgisi['ozet'] ?? hash('sha256', $this->temizle($json));
        if ($onceki = $this->aktarici->ayniDosya((int) $user->hane_id, $ozet)) {
            $alan = $pdfBilgisi !== null ? 'dosya' : 'json';
            throw ValidationException::withMessages([$alan => ($pdfBilgisi !== null ? 'Bu PDF' : 'Bu JSON').' '.$onceki->created_at->format('d.m.Y')
                .' tarihinde eklendi ('.$onceki->kart->ad.($onceki->donemEtiketi() ? ', '.$onceki->donemEtiketi().' ekstresi' : '').'). Aynı ekstre ikinci kez eklenmez.']);
        }

        // Kaynak satır doğrulaması yalnızca sunucunun PDF'ten okuyabildiği metinle yapılır
        $sayfaMetinleri = [];
        $metinsizKaynak = true;
        $sayfaSayisi = $this->sayfaSayisi($veri);
        if ($pdfBilgisi !== null) {
            try {
                $okunan = $this->analizci->sayfaMetinleri($pdfBilgisi['icerik']);
            } catch (YapayZekaHatasi $e) {
                throw ValidationException::withMessages(['dosya' => $e->getMessage()]);
            }
            $sayfaMetinleri = $okunan['sayfa_metinleri'];
            $sayfaSayisi = $okunan['sayfa_sayisi'];
            $metinsizKaynak = $okunan['metinsiz'];
        }

        ['kabul' => $kabul, 'reddedilen' => $reddedilen] = $this->islemDogrulayici->dogrula($veri['transactions'], $sayfaMetinleri, $sayfaSayisi, $metinsizKaynak);
        if ($kabul === []) {
            throw ValidationException::withMessages(['json' => $this->redNedenleri($veri['transactions'], $reddedilen)]);
        }

        $uyarilar = [];
        if ($metinsizKaynak) {
            $uyarilar[] = $pdfBilgisi === null
                ? "PDF'siz: satırlar dış yapay zekâdan geldi, kaynak satırlar doğrulanamadı. Ekstredeki toplamlarla karşılaştırın."
                : 'PDF taranmış (metni okunamadı): satırlar dış yapay zekâdan geldi, kaynak satırlar doğrulanamadı.';
            $kabul = $this->mutabakataGoreIsaretle($veri, $kabul, $uyarilar);
        }
        if ($reddedilen !== []) {
            $uyarilar[] = count($reddedilen).' satır doğrulamadan geçmedi ve alınmadı.';
            // Dış yapay zekânın yazdığı kaynak satır saklanmadan önce maskelenir
            $reddedilen = array_map(fn (array $r): array => [...$r, 'kaynak' => $this->temizleyici->maskele($r['kaynak'])], $reddedilen);
        }

        $yol = $pdfBilgisi !== null ? $this->yukleme->sakla($user, $pdfBilgisi['icerik'], 'ekstreler') : null;
        try {
            $ekstre = DB::transaction(function () use ($user, $kart, $veri, $ozet, $sayfaSayisi, $pdfBilgisi, $yol, $kabul, $reddedilen, $sayfaMetinleri, $metinsizKaynak, $uyarilar): Ekstre {
                $ekstre = $this->aktarici->bosTaslakOlustur($user, $kart, $ozet, $sayfaSayisi, $pdfBilgisi, $yol);
                $ekstre = $this->importer->uygula($ekstre, $user, [
                    'ai' => $this->temizleyici->maskeleHepsi($veri),
                    'kabul' => $kabul,
                    'reddedilen' => $reddedilen,
                    'sayfa_denetimi' => ['pdf_sayfa' => $sayfaSayisi],
                    'sayfa_metinleri' => $sayfaMetinleri,
                    'metinsiz_kaynak' => $metinsizKaynak,
                    'ek_uyarilar' => $uyarilar,
                    'harici' => true,
                    'model' => 'harici',
                ]);
                // Asgari ödeme yazılmadıysa kartın oranıyla hesaplanır (PDF okuyucu yolundaki gibi)
                $this->aktarici->alanlariGuncelle($ekstre, []);
                EkstreAiCalismasi::create([
                    'hane_id' => $ekstre->hane_id,
                    'ekstre_id' => $ekstre->id,
                    'user_id' => $user->id,
                    'model' => 'harici',
                    'durum' => 'basarili',
                    'asama' => 'tamam',
                    'calistirici' => 'yapistirma',
                    'sayfa_sayisi' => $sayfaSayisi,
                    'kabul_satir' => count($kabul),
                    'reddedilen_satir' => count($reddedilen),
                    'sure_ms' => 0,
                    'bitti_at' => now(),
                ]);

                return $ekstre->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            $this->yukleme->sil($yol);
            throw ValidationException::withMessages(['json' => 'Bu ekstre az önce eklendi; aynı ekstre ikinci kez eklenmez.']);
        } catch (Throwable $e) {
            $this->yukleme->sil($yol);
            throw $e;
        }

        $satirlar = $ekstre->islemler()->get();

        return [
            'ekstre' => $ekstre,
            'ozet' => [
                'satir' => $satirlar->count(),
                'inceleme' => $satirlar->filter(fn (KartIslemi $i): bool => $i->durum !== KartIslemDurumu::Atlandi && ($i->okuma['inceleme'] ?? false))->count(),
                'reddedilen' => count($reddedilen),
            ],
            'uyarilar' => $uyarilar,
        ];
    }

    /** Başındaki / sonundaki boşluk, BOM ve markdown çiti (```json … ```) soyulur; başka düzeltme yapılmaz */
    private function temizle(string $json): string
    {
        $json = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $json));
        if (preg_match('/^```[a-zA-Z0-9]*[ \t]*\R(.*?)\R?[ \t]*```$/s', $json, $e) === 1) {
            $json = trim($e[1]);
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function coz(string $json): array
    {
        $metin = $this->temizle($json);
        if ($metin === '') {
            throw ValidationException::withMessages(['json' => 'JSON boş.']);
        }
        $veri = json_decode($metin, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $neden = match (json_last_error()) {
                JSON_ERROR_DEPTH => 'çok derin iç içe yapı',
                JSON_ERROR_UTF8 => 'geçersiz karakter kodlaması',
                default => 'söz dizimi hatası (yarım kalmış, fazladan metin ya da tırnak / virgül hatası olabilir)',
            };
            throw ValidationException::withMessages(['json' => "Geçerli bir JSON değil: {$neden}. Yapay zekânın yanıtının tamamını, başka metin eklemeden yapıştırın."]);
        }
        if (! is_array($veri) || array_is_list($veri)) {
            throw ValidationException::withMessages(['json' => 'JSON tek bir nesne ({ … }) olmalı.']);
        }

        return $veri;
    }

    /**
     * @param  array<string, mixed>  $veri
     */
    private function semayaUydur(array $veri): void
    {
        $sema = json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true, flags: JSON_THROW_ON_ERROR);
        $hatalar = $this->jsonDogrulayici->dogrula($veri, $sema);
        if ($hatalar !== []) {
            throw ValidationException::withMessages(['json' => $this->hataListesi($hatalar)]);
        }
        if ($veri['statement_type'] !== 'credit_card') {
            throw ValidationException::withMessages(['json' => $veri['statement_type'] === 'bank_account'
                ? 'Bu JSON bir banka hesabı ekstresi (statement_type: bank_account). Şimdilik yalnızca kredi kartı ekstresi bu yolla eklenir.'
                : 'Belge kredi kartı ekstresi olarak tanınmadı (statement_type: unknown). Yalnızca kredi kartı ekstresi eklenir.']);
        }
    }

    /**
     * @param  list<string>  $hatalar
     * @return list<string>
     */
    private function hataListesi(array $hatalar): array
    {
        $cevir = fn (string $h): string => (string) preg_replace_callback('/beklenen tür ([a-z|]+)/', function (array $e): string {
            $ad = ['number' => 'sayı', 'integer' => 'tam sayı', 'string' => 'metin', 'boolean' => 'true / false', 'array' => 'liste', 'object' => 'nesne', 'null' => 'null'];
            $turler = array_map(fn (string $t): string => $ad[$t] ?? $t, explode('|', $e[1]));

            return implode(' ya da ', $turler).' olmalı';
        }, str_replace('şemada yok', 'fazla anahtar (şemada yok, silin)', $h));
        $liste = array_map($cevir, array_slice($hatalar, 0, self::MAX_HATA));
        if (count($hatalar) > self::MAX_HATA) {
            $liste[] = '… ve '.(count($hatalar) - self::MAX_HATA).' hata daha.';
        }

        return $liste;
    }

    /**
     * @param  list<array<string, mixed>>  $islemler
     * @param  list<array{neden: string, kaynak: string, sayfa: int}>  $reddedilen
     * @return list<string>
     */
    private function redNedenleri(array $islemler, array $reddedilen): array
    {
        if ($islemler === []) {
            return ['İşlem satırı yok (transactions boş): ekstre okunamamış olabilir. Yapay zekâya PDF\'i yeniden yükletip deneyin.'];
        }
        $liste = ['Hiçbir satır doğrulamadan geçmedi:'];
        foreach (array_slice($reddedilen, 0, 10) as $r) {
            $liste[] = 'Sayfa '.$r['sayfa'].': '.$r['neden'];
        }
        if (count($reddedilen) > 10) {
            $liste[] = '… ve '.(count($reddedilen) - 10).' satır daha.';
        }

        return $liste;
    }

    /**
     * PDF yoksa sayfa sayısı yapay zekânın page_audit'inden (ya da satırların en büyük sayfa numarasından) alınır.
     *
     * @param  array<string, mixed>  $veri
     */
    private function sayfaSayisi(array $veri): int
    {
        $sayfalar = array_merge(
            array_column($veri['page_audit'], 'page_number'),
            array_column($veri['transactions'], 'source_page'),
            [count($veri['page_audit'])],
        );

        return max(1, min(200, (int) max($sayfalar)));
    }

    /**
     * PDF'siz modda satırların tek güvencesi mutabakattır: borç denklemi ya da özet toplamları tutmuyorsa TÜM satırlar
     * inceleme ister (hangi satırın yanlış olduğu bilinemez); kontrol edilemiyorsa yalnızca uyarı.
     *
     * @param  array<string, mixed>  $veri
     * @param  list<array<string, mixed>>  $kabul
     * @param  list<string>  $uyarilar
     * @return list<array<string, mixed>>
     */
    private function mutabakataGoreIsaretle(array $veri, array $kabul, array &$uyarilar): array
    {
        $donemBorcu = is_numeric($veri['summary']['total_due'] ?? null) && (float) $veri['summary']['total_due'] >= 0
            ? Kurus::decimal((int) round(((float) $veri['summary']['total_due']) * 100)) : null;
        $m = $this->importer->mutabakat($veri, $donemBorcu, $kabul);
        if ($m['durum'] === 'kontrol_edilemedi') {
            $uyarilar[] = 'Mutabakat kontrol edilemedi (ekstrenin önceki borç / dönem borcu / toplamları JSON\'da yok): satırları ekstreyle karşılaştırın.';
        }
        if ($m['durum'] !== 'tutmuyor') {
            return $kabul;
        }
        $uyarilar[] = 'Borç denklemi / özet toplamları tutmuyor ('.number_format(abs((float) $m['fark']), 2, ',', '.').' TL fark): tüm satırlar inceleme gerektiriyor.';

        return array_map(function (array $s): array {
            $s['okuma']['inceleme'] = true;
            $s['okuma']['uyarilar'][] = 'Ekstrenin toplamları satırlarla tutmuyor; bu satırın doğru okunup okunmadığı kontrol edilmeli.';

            return $s;
        }, $kabul);
    }
}
