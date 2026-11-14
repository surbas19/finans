<?php

namespace App\Services\AIStatement;

use App\Support\Kurus;
use App\Support\Metin;

/**
 * Yapay zekânın işlem satırlarını tek tek doğrular; geçmeyen satır içeri alınmaz, nedeniyle birlikte raporlanır.
 * Kontroller: tarih, tutar, para birimi (yalnızca TL), işlem türü, kaynak sayfa, kaynak satır metninin O SAYFADA
 * gerçekten geçmesi (uydurma satır reddi) ve tutarın o satırda yazması. Geçenler ayrıştırıcı satır biçimine çevrilir.
 */
class TransactionValidator
{
    private const TURLER = [
        'purchase' => ['harcama', false],
        'payment' => ['odeme', true],
        'refund' => ['iade', true],
        'fee_interest' => ['faiz', false],
        'cash_advance' => ['nakit_avans', false],
        'transfer' => ['transfer', false],
        'bonus_redemption' => ['bonus', true],
    ];

    /**
     * @param  list<array<string, mixed>>  $islemler
     * @param  list<string>  $sayfaMetinleri  temizlenmiş sayfa metni (0 tabanlı); kaynak satır burada aranır
     * @return array{kabul: list<array<string, mixed>>, reddedilen: list<array{neden: string, kaynak: string, sayfa: int}>}
     */
    public function dogrula(array $islemler, array $sayfaMetinleri): array
    {
        $sayfaSade = array_map(fn (string $m): string => $this->bosluksuz($m), $sayfaMetinleri);
        $kabul = [];
        $reddedilen = [];
        foreach ($islemler as $i) {
            $neden = $this->neden($i, $sayfaSade);
            if ($neden !== null) {
                $reddedilen[] = ['neden' => $neden, 'kaynak' => mb_substr((string) ($i['source_text'] ?? ''), 0, 120), 'sayfa' => (int) ($i['source_page'] ?? 0)];

                continue;
            }
            [$tur, $alacak] = self::TURLER[$i['transaction_type']];
            $taksit = $i['installment_details'];
            $uyarilar = ['Yapay zekâ okudu; kaynak satırla karşılaştırın.'];
            if ((float) $i['confidence'] < 0.7) {
                $uyarilar[] = 'Düşük güven ('.round((float) $i['confidence'], 2).'): satırı kontrol edin.';
            }
            $kabul[] = [
                'sayfa' => (int) $i['source_page'],
                'ham' => (string) $i['source_text'],
                'islem_tarihi' => $i['date'],
                'aciklama' => mb_substr(trim((string) $i['description']), 0, 255),
                'tutar' => Kurus::decimal((int) round(((float) $i['amount']) * 100)),
                'alacak' => $alacak,
                'tur' => $tur,
                'taksit_no' => $taksit['number'] ?? null,
                'taksit_sayisi' => $taksit['count'] ?? null,
                'taksit_toplam' => isset($taksit['total_amount']) ? Kurus::decimal((int) round(((float) $taksit['total_amount']) * 100)) : null,
                'taksit_kalan' => isset($taksit['remaining_amount']) ? Kurus::decimal((int) round(((float) $taksit['remaining_amount']) * 100)) : null,
                'okuma' => ['alanlar' => [], 'uyarilar' => $uyarilar, 'tutar_adaylari' => []],
            ];
        }

        return ['kabul' => $kabul, 'reddedilen' => $reddedilen];
    }

    /**
     * @param  array<string, mixed>  $i
     * @param  list<string>  $sayfaSade
     */
    private function neden(array $i, array $sayfaSade): ?string
    {
        $tarih = $i['date'] ?? '';
        if (! is_string($tarih) || preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $tarih, $e) !== 1 || ! checkdate((int) $e[2], (int) $e[3], (int) $e[1])) {
            return 'Tarih geçersiz.';
        }
        $tutar = $i['amount'] ?? null;
        if (! is_numeric($tutar) || (float) $tutar <= 0 || (float) $tutar > 1e10) {
            return 'Tutar geçersiz.';
        }
        if (! in_array(strtoupper((string) ($i['currency'] ?? '')), ['TRY', 'TL'], true)) {
            return 'Para birimi TL değil.';
        }
        if (! isset(self::TURLER[$i['transaction_type'] ?? ''])) {
            return 'İşlem türü geçersiz.';
        }
        $sayfa = $i['source_page'] ?? null;
        if (! is_int($sayfa) || $sayfa < 1 || $sayfa > count($sayfaSade)) {
            return 'Kaynak sayfa geçersiz.';
        }
        $kaynak = $this->bosluksuz((string) ($i['source_text'] ?? ''));
        if (mb_strlen($kaynak) < 6 || ! str_contains($sayfaSade[$sayfa - 1], $kaynak)) {
            return 'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).';
        }
        $tr = number_format((float) $tutar, 2, ',', '.');
        if (! str_contains($kaynak, $this->bosluksuz($tr)) && ! str_contains($kaynak, str_replace('.', '', $this->bosluksuz($tr)))) {
            return 'Tutar, kaynak satırda yazmıyor.';
        }

        return null;
    }

    /** Karşılaştırma için: sade (büyük harf, işaretsiz) ve boşluksuz */
    private function bosluksuz(string $metin): string
    {
        return (string) preg_replace('/\s+/u', '', Metin::sade($metin));
    }
}
