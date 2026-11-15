<?php

namespace App\Services\AIStatement;

use App\Enums\KartIslemDurumu;
use App\Models\Ekstre;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Services\Ekstre\EkstreMutabakati;
use App\Support\Kurus;
use App\Support\Metin;
use Illuminate\Support\Facades\DB;

/**
 * Yapay zekâ sonucunu İNCELEMEDEKİ (taslak) kart ekstresine uygular; hiçbir zaman doğrudan finans hareketi yazmaz
 * (aktarım kullanıcının onayıyla, mevcut onay akışından).
 *
 * - PHP okuyucunun sonucu (mutabakat, teşhis, satır özeti) `denetim.php`'de saklanır ve çapraz kontrol yapılır
 *   (`denetim.capraz`): satır sayısı, sayfa başına sayılar, türe göre toplamlar, kesim / son ödeme tarihi, banka.
 * - Hangi satırlar taslak olur: yapay zekânınki; ancak PHP'nin borç denklemi tutuyor, yapay zekânınki tutmuyorsa PHP
 *   satırları kalır, yapay zekâ sonucu "öneri" olarak görünür.
 * - Kesim tarihleri farklıysa kullanıcı teyit edene kadar onay kapalı (aynı dönemin iki kez onaylanmasını bu tarih
 *   engeller).
 * - Boş dönem alanları yapay zekâdan doldurulur (kaynağı "ai"); dolu olanlara dokunulmaz.
 * - Olası tekrarlar (başka ekstrede aynı tarih / tutar) inceleme gerektirir.
 */
class StatementImporter
{
    public function __construct(
        private EkstreIceAktarici $aktarici,
        private EkstreMutabakati $mutabakat,
        private DuplicateDetector $tekrar,
        private StatementAuditService $denetim,
        private KisiselVeriTemizleyici $temizleyici,
    ) {}

    /**
     * Yapay zekânın yazdığı borç denklemi / özet toplamı denetimi (kuruş hassasiyetiyle). Dış yapay zekâ JSON'unda
     * (PDF'siz) satır incelemesini açıp açmamaya da bu karar verir.
     *
     * @param  array<string, mixed>  $ai
     * @param  list<array<string, mixed>>  $kabul
     * @return array<string, mixed>
     */
    public function mutabakat(array $ai, ?string $donemBorcu, array $kabul): array
    {
        return $this->mutabakat->denetle(
            ['donem_borcu' => $donemBorcu],
            array_filter([
                'onceki_borc' => self::tutar($ai['summary']['previous_balance'] ?? null, eksiOlabilir: true),
                'harcama_toplami' => self::tutar($ai['summary']['total_purchases'] ?? null),
                'faiz_toplami' => self::tutar($ai['summary']['total_interest'] ?? null),
                'odeme_toplami' => self::tutar($ai['summary']['total_payments'] ?? null),
            ], fn (?string $d): bool => $d !== null),
            $kabul,
        );
    }

    /**
     * @param  array{ai: array<string, mixed>, kabul: list<array<string, mixed>>, reddedilen: list<array<string, mixed>>, sayfa_denetimi: array<string, mixed>, model: string, sayfa_metinleri?: list<string>}  $analiz
     */
    public function uygula(Ekstre $ekstre, User $user, array $analiz): Ekstre
    {
        DB::transaction(function () use ($ekstre, $user, $analiz): void {
            $ai = $analiz['ai'];
            // Satır açıklaması ve kaynak satır da maskelenir (kart no, IBAN, telefon, kimlik, e-posta)
            $kabul = array_map(fn (array $s): array => [
                ...$s,
                'aciklama' => $this->temizleyici->maskele((string) $s['aciklama']),
                'ham' => $this->temizleyici->maskele((string) $s['ham']),
            ], $analiz['kabul']);
            $metinsizKaynak = (bool) ($analiz['metinsiz_kaynak'] ?? false);
            $onceki = $ekstre->denetim ?? [];
            // PHP okuyucunun sonucu ilk yapay zekâ çalışmasında özetlenir, sonraki çalışmalarda korunur
            $php = $onceki['php'] ?? [
                ...array_diff_key($onceki, array_flip(['ai', 'php', 'capraz', 'kesim_teyit_gerekli'])),
                'ozet' => $this->satirOzeti($ekstre->islemler()->where('durum', '!=', KartIslemDurumu::Atlandi->value)->get()
                    ->map(fn (KartIslemi $i): array => ['sayfa' => (int) $i->sayfa, 'tutar' => $i->tutar !== null ? (string) $i->tutar : null, 'tur' => $i->tur->value])->all()),
                'kesim_tarihi' => $ekstre->kesim_tarihi?->toDateString(),
                'son_odeme_tarihi' => $ekstre->son_odeme_tarihi?->toDateString(),
            ];

            $alanlar = [
                'kesim_tarihi' => self::tarih($ai['period']['cut_date'] ?? null),
                'son_odeme_tarihi' => self::tarih($ai['period']['due_date'] ?? null),
                'donem_baslangic' => self::tarih($ai['period']['start_date'] ?? null),
                'donem_borcu' => self::tutar($ai['summary']['total_due'] ?? null),
                'asgari_odeme' => self::tutar($ai['summary']['min_payment'] ?? null),
            ];
            $kaynaklar = $ekstre->alan_kaynaklari ?? [];
            $okunamayan = $ekstre->okunamayan ?? [];
            foreach ($alanlar as $alan => $deger) {
                $bos = $ekstre->getAttribute($alan) === null || ($alan === 'asgari_odeme' && ($kaynaklar[$alan] ?? null) === 'hesaplandi');
                if ($deger !== null && $bos) {
                    $ekstre->setAttribute($alan, $deger);
                    $kaynaklar[$alan] = 'ai';
                    $okunamayan = array_values(array_diff($okunamayan, [$alan]));
                }
            }

            $aiMutabakat = $this->mutabakat($ai, $ekstre->donem_borcu !== null ? (string) $ekstre->donem_borcu : null, $kabul);
            $phpSatir = (int) ($php['ozet']['satir'] ?? 0);
            $phpKalir = ($php['durum'] ?? null) === 'tutuyor' && $aiMutabakat['durum'] === 'tutmuyor' && $phpSatir > 0;
            $capraz = $this->capraz($php, $kabul, $aiMutabakat, $ai, $ekstre, $phpKalir);
            $sayfaDenetimi = $this->denetim->denetle(
                (int) ($analiz['sayfa_denetimi']['pdf_sayfa'] ?? $ekstre->sayfa_sayisi),
                $ai['page_audit'], $kabul, $analiz['reddedilen'], $analiz['sayfa_metinleri'] ?? [], $php['ozet']['sayfalar'] ?? [], $metinsizKaynak,
            );

            if (! $phpKalir) {
                $this->aktarici->satirlariYenile($ekstre, $user, $kabul);
            }
            $aiOzeti = [
                'model' => $analiz['model'],
                'banka' => mb_substr((string) ($ai['bank_name'] ?? ''), 0, 60),
                'tur' => $ai['statement_type'],
                'inceleme_gerekli' => (bool) $ai['review_required'],
                'uyarilar' => array_map(fn ($u): string => mb_substr((string) $u, 0, 200), [...($analiz['ek_uyarilar'] ?? []), ...array_slice($ai['warnings'], 0, 20)]),
                ...(isset($analiz['harici']) ? ['harici' => (bool) $analiz['harici'], 'metinsiz' => $metinsizKaynak] : []),
                'sayfa_denetimi' => $sayfaDenetimi,
                'reddedilen' => array_slice($analiz['reddedilen'], 0, 50),
                'bonus_hareketi' => count($ai['bonus_transactions']),
                'oneri' => $phpKalir,
                'mutabakat' => $phpKalir ? $aiMutabakat : null,
            ];
            $ekstre->forceFill([
                'alan_kaynaklari' => $kaynaklar,
                'okunamayan' => $okunamayan,
                // Maskeli standart JSON (JSON görünümü; liste ve özet yanıtlarına girmez)
                'ai_cikti' => $ai,
                ...($phpKalir ? [] : ['banka_profili' => 'ai', 'kalan_taksit' => $this->aktarici->kalanTaksit($kabul)]),
                'denetim' => [
                    ...($phpKalir ? array_diff_key($php, array_flip(['ozet', 'kesim_tarihi', 'son_odeme_tarihi'])) : $aiMutabakat),
                    'php' => $php,
                    'ai' => $aiOzeti,
                    'capraz' => $capraz,
                    'kesim_teyit_gerekli' => $capraz['kesim_farkli'],
                ],
            ])->save();
            if (! $phpKalir) {
                $this->tekrar->olasiTekrarlar($ekstre);
            }
        });

        return $ekstre->fresh();
    }

    /**
     * @param  list<array{sayfa: int, tutar: string|null, tur: string}>  $satirlar
     * @return array{satir: int, sayfalar: array<int, int>, toplamlar: array<string, string>}
     */
    private function satirOzeti(array $satirlar): array
    {
        $sayfalar = array_count_values(array_map(fn (array $s): int => (int) $s['sayfa'], $satirlar));
        ksort($sayfalar);

        return ['satir' => count($satirlar), 'sayfalar' => $sayfalar, 'toplamlar' => $this->mutabakat->denetle([], [], $satirlar)['toplamlar']];
    }

    /**
     * PHP okuyucu ↔ yapay zekâ karşılaştırması.
     *
     * @param  array<string, mixed>  $php
     * @param  list<array<string, mixed>>  $kabul
     * @param  array<string, mixed>  $aiMutabakat
     * @param  array<string, mixed>  $ai
     * @return array<string, mixed>
     */
    private function capraz(array $php, array $kabul, array $aiMutabakat, array $ai, Ekstre $ekstre, bool $phpKalir): array
    {
        $aiOzet = $this->satirOzeti(array_map(fn (array $s): array => ['sayfa' => $s['sayfa'], 'tutar' => $s['tutar'], 'tur' => $s['tur']], $kabul));
        $phpOzet = $php['ozet'] ?? ['satir' => 0, 'sayfalar' => [], 'toplamlar' => []];
        $tl = fn (?string $t): string => $t === null ? '—' : number_format((float) $t, 2, ',', '.');
        $farklar = [];
        if ($phpOzet['satir'] > 0) {
            if ($phpOzet['satir'] !== $aiOzet['satir']) {
                $farklar[] = "Satır sayısı: PDF okuyucu {$phpOzet['satir']}, yapay zekâ {$aiOzet['satir']}.";
            }
            foreach ($aiOzet['toplamlar'] as $tur => $tutar) {
                $phpTutar = $phpOzet['toplamlar'][$tur] ?? '0.00';
                if (Kurus::kurus($phpTutar) !== Kurus::kurus($tutar)) {
                    $farklar[] = "Toplam ({$tur}): PDF okuyucu {$tl($phpTutar)}, yapay zekâ {$tl($tutar)}.";
                }
            }
            foreach (array_unique([...array_keys($phpOzet['sayfalar']), ...array_keys($aiOzet['sayfalar'])]) as $sayfa) {
                if (($phpOzet['sayfalar'][$sayfa] ?? 0) !== ($aiOzet['sayfalar'][$sayfa] ?? 0)) {
                    $farklar[] = "Sayfa {$sayfa}: PDF okuyucu ".($phpOzet['sayfalar'][$sayfa] ?? 0).', yapay zekâ '.($aiOzet['sayfalar'][$sayfa] ?? 0).' satır.';
                }
            }
        }
        $aiKesim = self::tarih($ai['period']['cut_date'] ?? null);
        $phpKesim = $php['kesim_tarihi'] ?? null;
        $kesimFarkli = $aiKesim !== null && $phpKesim !== null && $aiKesim !== $phpKesim;
        if ($kesimFarkli) {
            $farklar[] = "Kesim tarihi: PDF okuyucu {$phpKesim}, yapay zekâ {$aiKesim}; kontrol edip kaydedin.";
        }
        $aiSonOdeme = self::tarih($ai['period']['due_date'] ?? null);
        if ($aiSonOdeme !== null && ($php['son_odeme_tarihi'] ?? null) !== null && $aiSonOdeme !== $php['son_odeme_tarihi']) {
            $farklar[] = "Son ödeme tarihi: PDF okuyucu {$php['son_odeme_tarihi']}, yapay zekâ {$aiSonOdeme}.";
        }
        if (($ai['statement_type'] ?? null) !== 'credit_card') {
            $farklar[] = 'Yapay zekâ bu belgeyi kredi kartı ekstresi olarak tanımadı ('.($ai['statement_type'] ?? '?').').';
        }
        $kartBankasi = $ekstre->kart?->banka?->ad;
        if ($kartBankasi !== null && ($ai['bank_name'] ?? null) !== null && ! $this->ayniBanka($kartBankasi, (string) $ai['bank_name'])) {
            $farklar[] = "Banka: kart {$kartBankasi}, PDF ".mb_substr((string) $ai['bank_name'], 0, 60).'.';
        }

        return [
            'durum' => $phpOzet['satir'] === 0 ? 'php_yok' : ($farklar === [] ? 'uyumlu' : 'farkli'),
            'php' => ['satir' => $phpOzet['satir'], 'mutabakat' => $php['durum'] ?? null, 'toplamlar' => $phpOzet['toplamlar']],
            'ai' => ['satir' => $aiOzet['satir'], 'mutabakat' => $aiMutabakat['durum'], 'toplamlar' => $aiOzet['toplamlar']],
            'secilen' => $phpKalir ? 'php' : 'ai',
            'farklar' => $farklar,
            'kesim_farkli' => $kesimFarkli,
        ];
    }

    /** "Garanti BBVA" ile "T. Garanti Bankası A.Ş." aynı banka: genel kelimeler dışında ortak bir kelime */
    private function ayniBanka(string $a, string $b): bool
    {
        $genel = ['BANKASI', 'BANKA', 'BANK', 'A.S.', 'AS', 'T.', 'TURK', 'TURKIYE', 'VE', 'KREDI', 'KARTI'];
        $kelimeler = fn (string $m): array => array_diff(array_filter(preg_split('/[\s\-]+/u', Metin::sade($m)) ?: [], fn (string $k): bool => mb_strlen($k) >= 3), $genel);

        return array_intersect($kelimeler($a), $kelimeler($b)) !== [];
    }

    private static function tarih(mixed $deger): ?string
    {
        return is_string($deger) && preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $deger, $e) === 1 && checkdate((int) $e[2], (int) $e[3], (int) $e[1]) ? $deger : null;
    }

    /** Önceki borç fazla ödemede eksi olabilir; diğer tutarlar değil */
    private static function tutar(mixed $deger, bool $eksiOlabilir = false): ?string
    {
        return is_numeric($deger) && ($eksiOlabilir || (float) $deger >= 0) && abs((float) $deger) <= 1e10 ? Kurus::decimal((int) round(((float) $deger) * 100)) : null;
    }
}
