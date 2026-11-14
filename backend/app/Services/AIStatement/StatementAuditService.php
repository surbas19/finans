<?php

namespace App\Services\AIStatement;

/**
 * Sayfa denetimi: her PDF sayfası için işlem tablosu var mı, yapay zekâ kaç işlem buldu, kaçı doğrulamadan geçti,
 * kaçı inceleme gerektiriyor; PDF sayfa sayısıyla analiz edilen sayfa sayısı karşılaştırılır.
 */
class StatementAuditService
{
    /**
     * @param  list<array<string, mixed>>  $modelSayfaDenetimi  yapay zekânın page_audit'i
     * @param  list<array<string, mixed>>  $kabul  doğrulamadan geçen satırlar
     * @param  list<array{neden: string, kaynak: string, sayfa: int}>  $reddedilen
     * @return array{sayfalar: list<array<string, mixed>>, pdf_sayfa: int, analiz_edilen_sayfa: int, sayfa_uyusuyor: bool}
     */
    public function denetle(int $pdfSayfa, array $modelSayfaDenetimi, array $kabul, array $reddedilen): array
    {
        $modelden = [];
        foreach ($modelSayfaDenetimi as $s) {
            $modelden[(int) ($s['page'] ?? 0)] = $s;
        }
        $reddedilenSayisi = array_count_values(array_column($reddedilen, 'sayfa'));
        ksort($modelden);
        $sayfalar = [];
        for ($n = 1; $n <= $pdfSayfa; $n++) {
            $kabulBu = count(array_filter($kabul, fn (array $s): bool => $s['sayfa'] === $n));
            $inceleme = count(array_filter($kabul, fn (array $s): bool => $s['sayfa'] === $n && count($s['okuma']['uyarilar']) > 1));
            $sayfalar[] = [
                'sayfa' => $n,
                'islem_tablosu_var' => (bool) ($modelden[$n]['has_transaction_table'] ?? false),
                'bulunan_islem' => (int) ($modelden[$n]['found_transactions'] ?? 0),
                'ayristirilan_islem' => $kabulBu,
                'inceleme_gereken' => $inceleme + ($reddedilenSayisi[$n] ?? 0),
                'durum' => isset($modelden[$n]) ? 'islendi' : 'modelde_yok',
            ];
        }

        return [
            'sayfalar' => $sayfalar,
            'pdf_sayfa' => $pdfSayfa,
            'analiz_edilen_sayfa' => count(array_filter($sayfalar, fn (array $s): bool => $s['durum'] === 'islendi')),
            'sayfa_uyusuyor' => count($modelden) >= $pdfSayfa && array_keys($modelden) === range(1, $pdfSayfa),
        ];
    }
}
