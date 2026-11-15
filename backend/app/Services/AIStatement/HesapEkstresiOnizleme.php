<?php

namespace App\Services\AIStatement;

use App\Models\HesapEkstresi;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Support\Kurus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Banka hesabı ekstresinin yapay zekâ sonucunu önizleme olarak yazar (Bütçe'ye aktarılmaz):
 *
 * - Şema denetimi; satır kuralları (tarih, tutar, yön, kaynak sayfa, kaynak satır o sayfada mı, tutar satırda mı,
 *   tekrar mı); taranmış sayfanın satırı inceleme gerektirir.
 * - Bakiye denetimi (kuruşla): satır satır yürüyen bakiye (önceki ± tutar = satırdaki bakiye), açılış + girenler −
 *   çıkanlar = kapanış, ekstrenin kendi giren / çıkan toplamları.
 * - Maskeli standart JSON, dönem, açılış / kapanış, sayfa denetimi. PDF işlem kesinleştikten sonra silinir.
 */
class HesapEkstresiOnizleme
{
    public function __construct(
        private JsonValidator $json,
        private StatementAuditService $denetim,
        private KisiselVeriTemizleyici $temizleyici,
        private DuplicateDetector $tekrar,
    ) {}

    /**
     * @param  array<string, mixed>  $veri
     * @param  list<string>  $sayfaMetinleri
     * @return array{0: int, 1: int} kabul edilen, reddedilen satır
     *
     * @throws YapayZekaHatasi
     */
    public function uygula(HesapEkstresi $hesap, array $veri, array $sayfaMetinleri, int $sayfaSayisi): array
    {
        $sema = json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($this->json->dogrula($veri, $sema) !== []) {
            throw YapayZekaHatasi::semaHatasi();
        }
        $sayfaSade = array_map(fn (string $m): string => TransactionValidator::bosluksuz($m), $sayfaMetinleri);
        $satirlar = [];
        $reddedilen = [];
        $kabul = [];
        $tekrarlar = $this->tekrar->ayikla($veri['transactions'], $sayfaSade);
        foreach ($veri['transactions'] as $sira => $i) {
            $inceleme = [];
            $neden = $this->neden($i, $sayfaSade, $sayfaSayisi, $inceleme) ?? $tekrarlar[$sira][1];
            if ($neden !== null) {
                $satirlar[] = ['durum' => 'red', 'neden' => $neden, 'inceleme' => []];
                $reddedilen[] = ['neden' => $neden, 'kaynak' => mb_substr((string) $i['source_text'], 0, 120), 'sayfa' => (int) $i['source_page']];

                continue;
            }
            if ($i['needs_review']) {
                $inceleme[] = 'Yapay zekâ: '.mb_substr(trim((string) ($i['review_reason'] ?? 'kontrol edilmeli')), 0, 120);
            }
            $satirlar[] = ['durum' => 'kabul', 'neden' => null, 'inceleme' => $inceleme];
            $kabul[] = ['sira' => $sira, 'sayfa' => (int) $i['source_page'], 'okuma' => ['inceleme' => $inceleme !== []], ...$i];
        }
        $bakiye = $this->bakiyeDenetimi($veri['summary'], $kabul);

        $maskeli = $this->temizleyici->maskeleHepsi($veri);
        $yol = $hesap->dosya_yolu;
        $hesap->forceFill([
            'durum' => 'onizleme',
            'banka_adi' => mb_substr((string) ($maskeli['bank_name'] ?? ''), 0, 60) ?: null,
            'donem_baslangic' => self::tarih($veri['period']['start_date'] ?? null),
            'donem_bitis' => self::tarih($veri['period']['end_date'] ?? null),
            'acilis_bakiyesi' => self::tutar($veri['summary']['opening_balance'] ?? null),
            'kapanis_bakiyesi' => self::tutar($veri['summary']['closing_balance'] ?? null),
            'ai_cikti' => $maskeli,
            'denetim' => [
                'tur' => $veri['statement_type'],
                'bakiye' => $bakiye,
                'satirlar' => $satirlar,
                'reddedilen' => array_slice($reddedilen, 0, 50),
                'sayfa_denetimi' => $this->denetim->denetle($sayfaSayisi, $veri['page_audit'], $kabul, $reddedilen, $sayfaMetinleri),
                'uyarilar' => array_map(fn ($u): string => mb_substr((string) $this->temizleyici->maskele((string) $u), 0, 200), array_slice($veri['warnings'], 0, 20)),
            ],
            'dosya_yolu' => null,
        ])->save();
        // PDF analizden sonra tutulmaz (yalnızca önizleme); geri alınan işlemde dosya kalır
        DB::afterCommit(fn () => $yol !== null && Storage::disk(EkstreIceAktarici::DISK)->delete($yol));

        return [count($kabul), count($reddedilen)];
    }

    /**
     * @param  array<string, mixed>  $i
     * @param  list<string>  $sayfaSade
     * @param  list<string>  $inceleme
     */
    private function neden(array $i, array $sayfaSade, int $sayfaSayisi, array &$inceleme): ?string
    {
        $tarih = $i['date'];
        if ($tarih !== null && (preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', (string) $tarih, $e) !== 1 || ! checkdate((int) $e[2], (int) $e[3], (int) $e[1]))) {
            return 'Tarih geçersiz.';
        }
        if ($tarih === null) {
            $inceleme[] = 'Tarih okunamadı.';
        }
        if (! is_numeric($i['amount']) || (float) $i['amount'] <= 0 || (float) $i['amount'] > 1e10) {
            return 'Tutar geçersiz.';
        }
        if (! in_array(strtoupper((string) $i['currency']), ['TRY', 'TL'], true)) {
            $inceleme[] = 'Para birimi TL değil ('.$i['currency'].').';
        }
        $sayfa = $i['source_page'];
        if ($sayfa < 1 || $sayfa > $sayfaSayisi) {
            return 'Kaynak sayfa geçersiz.';
        }
        $kaynak = TransactionValidator::bosluksuz((string) $i['source_text']);
        $metin = $sayfaSade[$sayfa - 1] ?? '';
        if ($metin === '') {
            $inceleme[] = 'Sayfada metin yok: PDF görüntüsünden okundu.';
        } elseif (mb_strlen($kaynak) < 6 || ! str_contains($metin, $kaynak)) {
            return 'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).';
        } else {
            $yazili = number_format((float) $i['amount'], 2, ',', '.');
            if (! str_contains($kaynak, TransactionValidator::bosluksuz($yazili)) && ! str_contains($kaynak, str_replace('.', '', $yazili))) {
                return 'Tutar, kaynak satırda yazmıyor.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ozet
     * @param  list<array<string, mixed>>  $kabul
     * @return array{durum: string, kontroller: list<array<string, mixed>>, satir_hatalari: list<array<string, mixed>>}
     */
    private function bakiyeDenetimi(array $ozet, array $kabul): array
    {
        $k = fn (mixed $t): ?int => is_numeric($t) ? (int) round(((float) $t) * 100) : null;
        $giren = 0;
        $cikan = 0;
        $onceki = $k($ozet['opening_balance'] ?? null);
        $satirHatalari = [];
        foreach ($kabul as $s) {
            $tutar = $k($s['amount']);
            $s['direction'] === 'credit' ? $giren += $tutar : $cikan += $tutar;
            $bakiye = $k($s['balance'] ?? null);
            if ($bakiye !== null && $onceki !== null) {
                $beklenen = $onceki + ($s['direction'] === 'credit' ? $tutar : -$tutar);
                if ($beklenen !== $bakiye) {
                    $satirHatalari[] = ['sira' => $s['sira'], 'beklenen' => Kurus::decimal($beklenen), 'bulunan' => Kurus::decimal($bakiye), 'fark' => Kurus::decimal($bakiye - $beklenen)];
                }
            }
            // Satırdaki bakiye yazılıysa sonraki satır ondan yürür (bir hata zincirleme hata saydırmaz)
            $onceki = $bakiye ?? ($onceki !== null ? $onceki + ($s['direction'] === 'credit' ? $tutar : -$tutar) : null);
        }

        $kontroller = [];
        $acilis = $k($ozet['opening_balance'] ?? null);
        $kapanis = $k($ozet['closing_balance'] ?? null);
        $kontroller[] = $acilis !== null && $kapanis !== null
            ? $this->kontrol('acilis_kapanis', 'Açılış + girenler − çıkanlar = kapanış bakiyesi', $kapanis, $acilis + $giren - $cikan)
            : ['ad' => 'acilis_kapanis', 'etiket' => 'Açılış / kapanış bakiyesi', 'durum' => 'kontrol_edilemedi', 'neden' => 'Açılış ya da kapanış bakiyesi okunamadı.'];
        foreach ([['total_credits', 'Girenler toplamı (ekstre = satırlar)', $giren], ['total_debits', 'Çıkanlar toplamı (ekstre = satırlar)', $cikan]] as [$alan, $etiket, $satirlardan]) {
            if (($t = $k($ozet[$alan] ?? null)) !== null) {
                $kontroller[] = $this->kontrol($alan, $etiket, $t, $satirlardan);
            }
        }
        $kontroller[] = ['ad' => 'yuruyen_bakiye', 'etiket' => 'Satır satır bakiye', 'durum' => $satirHatalari === [] ? 'tutuyor' : 'tutmuyor', 'hatali_satir' => count($satirHatalari)];
        $tutmayan = array_filter($kontroller, fn (array $c): bool => $c['durum'] === 'tutmuyor');

        return [
            'durum' => $tutmayan !== [] ? 'tutmuyor' : (array_filter($kontroller, fn (array $c): bool => $c['durum'] === 'tutuyor') !== [] ? 'tutuyor' : 'kontrol_edilemedi'),
            'kontroller' => $kontroller,
            'satir_hatalari' => array_slice($satirHatalari, 0, 50),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kontrol(string $ad, string $etiket, int $beklenen, int $bulunan): array
    {
        return [
            'ad' => $ad, 'etiket' => $etiket, 'durum' => $beklenen === $bulunan ? 'tutuyor' : 'tutmuyor',
            'beklenen' => Kurus::decimal($beklenen), 'bulunan' => Kurus::decimal($bulunan), 'fark' => Kurus::decimal($bulunan - $beklenen),
        ];
    }

    private static function tarih(mixed $deger): ?string
    {
        return is_string($deger) && preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $deger, $e) === 1 && checkdate((int) $e[2], (int) $e[3], (int) $e[1]) ? $deger : null;
    }

    private static function tutar(mixed $deger): ?string
    {
        return is_numeric($deger) && abs((float) $deger) <= 1e10 ? Kurus::decimal((int) round(((float) $deger) * 100)) : null;
    }
}
