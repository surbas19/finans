<?php

namespace Tests\Support;

use App\Support\Metin;
use RuntimeException;

/**
 * Garanti referans ekstresi için yapay zekâ yanıtı (standart JSON şeması v2): beklenen satırlardan (fixture) ve
 * sunucunun sentetik PDF'ten okuduğu sayfa metninden kurulur. Gerçek model `source_text`'i gönderilen "[SAYFA n]"
 * metninden aynen kopyalar; burada da her işlemin kaynak satırı o metinde sırayla aranır. Anahtar olmadığı için gerçek
 * yanıt kaydedilemedi; bu, doğru çalışan bir modelin çıktısıdır (doğrulama motorunun her kuralından geçmeli).
 */
final class GarantiAiYaniti
{
    private const TURLER = ['odeme' => 'payment', 'iade' => 'refund', 'faiz' => 'interest', 'vergi' => 'tax', 'ucret' => 'fee', 'bonus' => 'bonus_redemption', 'nakit_avans' => 'cash_advance', 'transfer' => 'transfer'];

    /**
     * @param  array<string, mixed>  $beklenen  tests/Fixtures/ekstre/garanti_bonus/beklenen.json
     * @param  list<string>  $sayfaMetinleri  StatementAnalyzer::sayfaMetinleri()['sayfa_metinleri']
     * @return array<string, mixed>
     */
    public static function olustur(array $beklenen, array $sayfaMetinleri): array
    {
        $satirlar = array_map(fn (string $m): array => preg_split('/\R/u', $m) ?: [], $sayfaMetinleri);
        $sonraki = [];
        $islemler = [];
        foreach ($beklenen['satirlar'] as $s) {
            $islemler[] = [
                'date' => $s['islem_tarihi'],
                'description' => $s['aciklama'],
                'amount' => (float) $s['tutar'],
                'currency' => 'TRY',
                'direction' => $s['alacak'] ? 'credit' : 'debit',
                'transaction_type' => self::TURLER[$s['tur']] ?? ($s['taksit_sayisi'] !== null ? 'installment' : 'purchase'),
                'original_currency' => null, 'original_amount' => null, 'balance' => null, 'bonus_earned' => null,
                'source_page' => $s['sayfa'],
                'source_text' => self::kaynak($satirlar, $sonraki, $s),
                'confidence' => 0.97, 'needs_review' => false, 'review_reason' => null,
                'installment_details' => [
                    'number' => $s['taksit_no'], 'count' => $s['taksit_sayisi'],
                    'total_amount' => $s['taksit_toplam'] !== null ? (float) $s['taksit_toplam'] : null, 'remaining_amount' => null,
                ],
            ];
        }
        $sayfalar = array_count_values(array_column($beklenen['satirlar'], 'sayfa'));
        $ozet = $beklenen['ozet'];

        return [
            'bank_name' => 'Garanti BBVA',
            'statement_type' => 'credit_card',
            'account' => ['label' => 'Bonus Classic', 'currency' => 'TRY'],
            'period' => [
                'start_date' => $beklenen['alanlar']['donem_baslangic'], 'end_date' => null,
                'cut_date' => $beklenen['alanlar']['kesim_tarihi'], 'due_date' => $beklenen['alanlar']['son_odeme_tarihi'],
            ],
            'summary' => [
                'previous_balance' => (float) $ozet['onceki_borc'], 'total_due' => (float) $beklenen['alanlar']['donem_borcu'],
                'min_payment' => (float) $beklenen['alanlar']['asgari_odeme'], 'total_purchases' => (float) $ozet['harcama_toplami'],
                'total_payments' => (float) $ozet['odeme_toplami'], 'total_refunds' => null, 'total_interest' => (float) $ozet['faiz_toplami'],
                'total_fees' => null, 'total_taxes' => null, 'total_bonus_used' => null, 'remaining_installments' => null,
                'opening_balance' => null, 'closing_balance' => null, 'total_credits' => null, 'total_debits' => null,
            ],
            'transactions' => $islemler,
            'installments' => [],
            'bonus_transactions' => array_map(fn (array $b): array => [
                'date' => $b['islem_tarihi'], 'description' => $b['aciklama'], 'bonus_amount' => (float) $b['tutar'],
                'direction' => $b['yon'] === 'kazanilan' ? 'earned' : 'used', 'source_page' => $b['sayfa'],
            ], $beklenen['bonus_hareketleri']),
            'page_audit' => array_map(fn (int $n): array => [
                'page_number' => $n, 'has_transaction_table' => isset($sayfalar[$n]), 'found_count' => $sayfalar[$n] ?? 0,
                'status' => isset($sayfalar[$n]) ? 'processed' : 'no_transactions',
            ], range(1, $beklenen['sayfa_sayisi'])),
            'warnings' => [],
            'review_required' => false,
        ];
    }

    /**
     * Satırın sayfa metnindeki kaynağı: tarih, tutar ve açıklama geçen ilk kullanılmamış satır (sırayla).
     *
     * @param  list<list<string>>  $satirlar
     * @param  array<int, int>  $sonraki
     * @param  array<string, mixed>  $s
     */
    private static function kaynak(array $satirlar, array &$sonraki, array $s): string
    {
        $sayfa = $s['sayfa'] - 1;
        $tarih = $s['islem_tarihi'] !== null ? date('d.m.Y', strtotime($s['islem_tarihi'])) : '';
        $tutar = number_format((float) $s['tutar'], 2, ',', '.');
        for ($i = $sonraki[$sayfa] ?? 0; $i < count($satirlar[$sayfa] ?? []); $i++) {
            $satir = $satirlar[$sayfa][$i];
            if (str_contains($satir, $tarih) && str_contains($satir, $tutar) && str_contains(Metin::sade($satir), Metin::sade($s['aciklama']))) {
                $sonraki[$sayfa] = $i + 1;

                return $satir;
            }
        }
        throw new RuntimeException("Kaynak satır bulunamadı: satır {$s['sira']}");
    }
}
