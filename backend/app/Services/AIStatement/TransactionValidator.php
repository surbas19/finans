<?php

namespace App\Services\AIStatement;

use App\Support\Kurus;
use App\Support\Metin;

/**
 * Yapay zekânın kredi kartı işlem satırlarını tek tek doğrular. Geçmeyen satır içeri alınmaz, nedeniyle raporlanır;
 * şüpheli satır alınır ama "inceleme gerekli" işaretlenir (kullanıcı "Kontrol ettim" demeden aktarılmaz).
 *
 * Ret: tarih yazılmış ama geçersiz, tutar geçersiz, para birimi TL değil, tür / yön geçersiz, banka hesabı hareketi,
 * kaynak sayfa aralık dışı, kaynak satır sayfada yok (uydurma), tutar kaynak satırda yok, özet satırı (devreden borç,
 * toplam, asgari ödeme), aynı kaynak satırın sayfadakinden fazla tekrarı (DuplicateDetector).
 * İnceleme: sayfada metin yok (taranmış; PDF görüntüsünden okundu), kaynak satır birebir değil benzer, satırda birden
 * çok tutar ve seçilen en sağdaki değil, tür ile yön uyuşmuyor, model "needs_review" dedi, düşük güven, tarih okunamadı.
 */
class TransactionValidator
{
    /** Kredi kartı satırı: şema türü → kart işlemi türü (null: yönüne göre harcama / iade) */
    private const TURLER = [
        'purchase' => 'harcama',
        'installment' => 'harcama',
        'refund' => 'iade',
        'payment' => 'odeme',
        'bonus_redemption' => 'bonus',
        'interest' => 'faiz',
        'fee' => 'ucret',
        'tax' => 'vergi',
        'cash_advance' => 'nakit_avans',
        'transfer' => 'transfer',
        'other' => null,
    ];

    /** Türün beklenen yönü (transfer / other: ikisi de olur) */
    private const YONLER = [
        'purchase' => 'debit', 'installment' => 'debit', 'interest' => 'debit', 'fee' => 'debit', 'tax' => 'debit', 'cash_advance' => 'debit',
        'refund' => 'credit', 'payment' => 'credit', 'bonus_redemption' => 'credit',
    ];

    /** İşlem olmayan özet satırları (sade) */
    private const OZET = ['ONCEKI DONEM', 'DEVIR EDILEN', 'DEVREDEN', 'TOPLAM BORC', 'DONEM BORCU', 'ASGARI ODEME', 'MIN. ODEME', 'HESAP OZETI', 'GENEL TOPLAM'];

    private const TUTAR = '/\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}/u';

    public function __construct(private DuplicateDetector $tekrar) {}

    /**
     * @param  list<array<string, mixed>>  $islemler
     * @param  list<string>  $sayfaMetinleri  temizlenmiş sayfa metni (0 tabanlı); kaynak satır burada aranır
     * @param  int|null  $sayfaSayisi  PDF'in fiziksel sayfa sayısı (verilmezse metin sayısı)
     * @param  bool  $metinsizKaynak  PDF yok (dış yapay zekâ JSON'u): kaynak satır sayfada aranamaz. Satıra "sayfada
     *                                metin yok" işareti konmaz (75 satır = 75 onay olmasın); tutar yine kaynak satırda
     *                                geçmeli, aynı kaynak satırın ikinci gelişi inceleme ister
     * @return array{kabul: list<array<string, mixed>>, reddedilen: list<array{neden: string, kaynak: string, sayfa: int}>}
     */
    public function dogrula(array $islemler, array $sayfaMetinleri, ?int $sayfaSayisi = null, bool $metinsizKaynak = false): array
    {
        $sayfaSayisi ??= count($sayfaMetinleri);
        $sayfaSade = array_map(fn (string $m): string => self::bosluksuz($m), $sayfaMetinleri);
        $kabul = [];
        $reddedilen = [];
        $red = function (array $i, string $neden) use (&$reddedilen): void {
            $reddedilen[] = ['neden' => $neden, 'kaynak' => mb_substr((string) ($i['source_text'] ?? ''), 0, 120), 'sayfa' => (int) ($i['source_page'] ?? 0)];
        };
        // Önce satır kuralları; tekrar sayımı yalnızca bunlardan geçenlerle (geçersiz satır geçerli olanı "tekrar" yapmasın)
        $adaylar = [];
        foreach ($islemler as $i) {
            $inceleme = [];
            $neden = $this->neden($i, $sayfaMetinleri, $sayfaSade, $sayfaSayisi, $inceleme, $metinsizKaynak);
            if ($neden !== null) {
                $red($i, $neden);

                continue;
            }
            $adaylar[] = [$i, $inceleme];
        }
        $tekrarlar = $this->tekrar->ayikla(array_column($adaylar, 0), $sayfaSade);
        $ikinciler = $metinsizKaynak ? $this->tekrar->metinsizTekrarlar(array_column($adaylar, 0)) : [];
        foreach ($adaylar as $k => [$i, $inceleme]) {
            if ($tekrarlar[$k][1] !== null) {
                $red($i, $tekrarlar[$k][1]);

                continue;
            }
            $alacak = $i['direction'] === 'credit';
            $tur = self::TURLER[$i['transaction_type']] ?? ($alacak ? 'iade' : 'harcama');
            $taksit = $i['installment_details'];
            if ($ikinciler[$k] ?? false) {
                $inceleme[] = 'Aynı kaynak satır ikinci kez geliyor: PDF yok, ayrı bir işlem mi yoksa tekrar mı kontrol edin.';
            }
            if ($i['date'] === null) {
                $inceleme[] = 'Tarih okunamadı.';
            }
            if ($i['needs_review'] ?? false) {
                $inceleme[] = 'Yapay zekâ: '.mb_substr(trim((string) ($i['review_reason'] ?? 'kontrol edilmeli')), 0, 120);
            }
            if ((float) $i['confidence'] < 0.7) {
                $inceleme[] = 'Düşük güven ('.round((float) $i['confidence'], 2).').';
            }
            $kabul[] = [
                'sayfa' => (int) $i['source_page'],
                'ham' => (string) $i['source_text'],
                'islem_tarihi' => $i['date'],
                'aciklama' => mb_substr(trim((string) $i['description']), 0, 255),
                'tutar' => self::kurus($i['amount']),
                'alacak' => $alacak,
                'tur' => $tur,
                'taksit_no' => $taksit['number'] ?? null,
                'taksit_sayisi' => $taksit['count'] ?? null,
                'taksit_toplam' => isset($taksit['total_amount']) ? self::kurus($taksit['total_amount']) : null,
                'taksit_kalan' => isset($taksit['remaining_amount']) ? self::kurus($taksit['remaining_amount']) : null,
                'okuma' => [
                    'alanlar' => $i['date'] === null ? ['islem_tarihi'] : [],
                    'uyarilar' => ['Yapay zekâ okudu; kaynak satırla karşılaştırın.', ...$inceleme],
                    'tutar_adaylari' => [],
                    'inceleme' => $inceleme !== [],
                    'incelendi' => false,
                ],
            ];
        }

        return ['kabul' => $kabul, 'reddedilen' => $reddedilen];
    }

    /**
     * Ret nedeni; şüpheli ama kabul edilen durumlar `$inceleme`ye eklenir.
     *
     * @param  array<string, mixed>  $i
     * @param  list<string>  $sayfaMetinleri
     * @param  list<string>  $sayfaSade
     * @param  list<string>  $inceleme
     */
    private function neden(array $i, array $sayfaMetinleri, array $sayfaSade, int $sayfaSayisi, array &$inceleme, bool $metinsizKaynak = false): ?string
    {
        // Tarih okunamadıysa (null) satır alınır, "okunamadı" işaretlenir; yazılmış ama geçersizse ret
        $tarih = $i['date'] ?? null;
        if ($tarih !== null && (! is_string($tarih) || preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $tarih, $e) !== 1 || ! checkdate((int) $e[2], (int) $e[3], (int) $e[1]))) {
            return 'Tarih geçersiz.';
        }
        $tutar = $i['amount'] ?? null;
        if (! is_numeric($tutar) || (float) $tutar <= 0 || (float) $tutar > 1e10) {
            return 'Tutar geçersiz.';
        }
        if (! in_array(strtoupper((string) ($i['currency'] ?? '')), ['TRY', 'TL'], true)) {
            return 'Para birimi TL değil.';
        }
        $tur = $i['transaction_type'] ?? '';
        if (in_array($tur, ['deposit', 'withdrawal'], true)) {
            return 'Banka hesabı hareketi kredi kartı ekstresinde olamaz.';
        }
        if (! array_key_exists($tur, self::TURLER) || ! in_array($i['direction'] ?? null, ['debit', 'credit'], true)) {
            return 'İşlem türü ya da yönü geçersiz.';
        }
        $sayfa = $i['source_page'] ?? null;
        if (! is_int($sayfa) || $sayfa < 1 || $sayfa > $sayfaSayisi) {
            return 'Kaynak sayfa geçersiz.';
        }
        $kaynakMetin = (string) ($i['source_text'] ?? '');
        $kaynak = self::bosluksuz($kaynakMetin);
        $sade = Metin::sade($kaynakMetin.' '.($i['description'] ?? ''));
        foreach (self::OZET as $ozet) {
            if (str_contains($sade, $ozet)) {
                return 'Özet satırı (devreden borç, toplam, asgari ödeme): işlem değil.';
            }
        }
        $sayfaMetni = $sayfaSade[$sayfa - 1] ?? '';
        if ($metinsizKaynak) {
            // PDF yok: kaynak satır doğrulanamaz; ekstre düzeyindeki uyarı yeter, satır satır işaret konmaz
        } elseif ($sayfaMetni === '') {
            // Taranmış sayfa: sunucuda metin yok, satır görüntüden okundu; doğrulanamaz, kullanıcı kontrol eder
            $inceleme[] = 'Sayfada metin yok: PDF görüntüsünden okundu.';
        } elseif (mb_strlen($kaynak) < 6) {
            return 'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).';
        } elseif (! str_contains($sayfaMetni, $kaynak)) {
            if (! $this->benzerSatirVar($kaynakMetin, (float) $tutar, $sayfaMetinleri[$sayfa - 1] ?? '')) {
                return 'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).';
            }
            $inceleme[] = 'Kaynak satır sayfada birebir yok; benzer satır bulundu.';
        }
        $tutarlar = $this->tutarlar($kaynakMetin);
        $yazili = number_format((float) $tutar, 2, ',', '.');
        if (! in_array($yazili, $tutarlar, true) && ! in_array(str_replace('.', '', $yazili), $tutarlar, true)) {
            return 'Tutar, kaynak satırda yazmıyor.';
        }
        // Satırda birden çok tutar (taksit toplamı, bonus / puan sütunu, bakiye): TL tutar çoğunlukla en sağdaki
        if (count($tutarlar) > 1 && ! in_array(end($tutarlar), [$yazili, str_replace('.', '', $yazili)], true)
            && ! $this->ekTutarlarAciklanmis($i, $tutarlar, $yazili)) {
            $inceleme[] = 'Satırda birden çok tutar var; seçilen tutar en sağdaki değil.';
        }
        $beklenen = self::YONLER[$tur] ?? null;
        if ($beklenen !== null && $beklenen !== $i['direction']) {
            $inceleme[] = 'Tür ile yön uyuşmuyor ('.$tur.' / '.$i['direction'].').';
        }

        return null;
    }

    /**
     * Sayfada, tutarı içeren ve kelimelerinin çoğu (≥ %60) kaynak satırla ortak bir satır var mı.
     */
    private function benzerSatirVar(string $kaynak, float $tutar, string $sayfaMetni): bool
    {
        $kelimeler = fn (string $m): array => array_values(array_filter(preg_split('/\s+/u', Metin::sade($m)) ?: [], fn (string $k): bool => mb_strlen($k) >= 2));
        $aranan = $kelimeler($kaynak);
        if ($aranan === []) {
            return false;
        }
        $yazili = number_format($tutar, 2, ',', '.');
        foreach (preg_split('/\R/u', $sayfaMetni) ?: [] as $satir) {
            if (! str_contains($satir, $yazili) && ! str_contains($satir, str_replace('.', '', $yazili))) {
                continue;
            }
            $ortak = count(array_intersect($aranan, $kelimeler($satir)));
            if ($ortak / count($aranan) >= 0.6) {
                return true;
            }
        }

        return false;
    }

    /**
     * Satırdaki diğer tutarların hepsi satırın kendi taksit / döviz / bakiye alanlarıyla açıklanıyorsa (ör. Yapı Kredi:
     * "1.757,80  8.789,00 / 5  15.820,17 TL'lik işlemin 4 / 9 taksidi": kalan ve toplam) TL tutar solda olsa da şüphe yoktur.
     *
     * @param  array<string, mixed>  $i
     * @param  list<string>  $tutarlar
     */
    private function ekTutarlarAciklanmis(array $i, array $tutarlar, string $yazili): bool
    {
        $bilinen = [];
        foreach ([$i['installment_details']['total_amount'] ?? null, $i['installment_details']['remaining_amount'] ?? null, $i['original_amount'] ?? null, $i['balance'] ?? null] as $d) {
            if (is_numeric($d)) {
                $bilinen[] = (float) $d;
            }
        }
        $digerleri = array_values(array_filter($tutarlar, fn (string $t): bool => ! in_array($t, [$yazili, str_replace('.', '', $yazili)], true)));
        if ($digerleri === [] || $bilinen === []) {
            return false;
        }
        foreach ($digerleri as $t) {
            $deger = (float) str_replace(',', '.', str_replace('.', '', $t));
            if (! array_filter($bilinen, fn (float $b): bool => abs($b - $deger) < 0.005)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Satırdaki para tutarları, soldan sağa (taksit "A x N = T" içindekiler dahil).
     *
     * @return list<string>
     */
    private function tutarlar(string $metin): array
    {
        preg_match_all(self::TUTAR, $metin, $e);

        return $e[0];
    }

    private static function kurus(mixed $tutar): string
    {
        return Kurus::decimal((int) round(((float) $tutar) * 100));
    }

    /** Karşılaştırma için: sade (büyük harf, işaretsiz) ve boşluksuz */
    public static function bosluksuz(string $metin): string
    {
        return (string) preg_replace('/\s+/u', '', Metin::sade($metin));
    }
}
