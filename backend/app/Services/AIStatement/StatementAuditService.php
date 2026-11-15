<?php

namespace App\Services\AIStatement;

/**
 * Sayfa denetimi: PDF'in her sayfası için modelin bulduğu işlem sayısı, sunucunun doğrulayıp aldığı, incelemesi
 * gereken, reddedilen ve (varsa) PHP okuyucunun bulduğu satır sayısı, sayfada metin olup olmadığı ve durum:
 * tamam · eksik (model daha çok satır gördü) · fazla · metinsiz (taranmış; görüntüden okundu) · modelde_yok (model o
 * sayfayı raporlamadı). PDF sayfa sayısı ile analiz edilen sayfa sayısı karşılaştırılır.
 */
class StatementAuditService
{
    /**
     * @param  list<array<string, mixed>>  $modelSayfaDenetimi  yapay zekânın page_audit'i
     * @param  list<array<string, mixed>>  $kabul  doğrulamadan geçen satırlar
     * @param  list<array{neden: string, kaynak: string, sayfa: int}>  $reddedilen
     * @param  list<string>  $sayfaMetinleri  sunucunun okuduğu sayfa metni (boş: taranmış)
     * @param  array<int, int>  $phpSayfalari  PHP okuyucunun sayfa başına satır sayısı (1 tabanlı)
     * @param  bool  $metinsizKaynak  PDF yok (dış yapay zekâ JSON'u): hiçbir sayfanın metni yoktur (`metin_var` false) ama
     *                                bu "taranmış sayfa" sorunu değildir; durum yalnızca satır sayılarından çıkar
     * @return array{sayfalar: list<array<string, mixed>>, pdf_sayfa: int, analiz_edilen_sayfa: int, sayfa_uyusuyor: bool, sorunlu_sayfa: int}
     */
    public function denetle(int $pdfSayfa, array $modelSayfaDenetimi, array $kabul, array $reddedilen, array $sayfaMetinleri = [], array $phpSayfalari = [], bool $metinsizKaynak = false): array
    {
        $modelden = [];
        foreach ($modelSayfaDenetimi as $s) {
            $modelden[(int) ($s['page_number'] ?? 0)] = $s;
        }
        $reddedilenSayisi = array_count_values(array_column($reddedilen, 'sayfa'));
        ksort($modelden);
        $sayfalar = [];
        for ($n = 1; $n <= $pdfSayfa; $n++) {
            $bu = array_filter($kabul, fn (array $s): bool => $s['sayfa'] === $n);
            $inceleme = count(array_filter($bu, fn (array $s): bool => (bool) ($s['okuma']['inceleme'] ?? false)));
            $bulunan = (int) ($modelden[$n]['found_count'] ?? 0);
            $metinVar = ! $metinsizKaynak && trim($sayfaMetinleri[$n - 1] ?? 'x') !== '';
            $durum = match (true) {
                ! isset($modelden[$n]) => 'modelde_yok',
                ! $metinVar && ! $metinsizKaynak => 'metinsiz',
                count($bu) < $bulunan => 'eksik',
                count($bu) > $bulunan => 'fazla',
                default => 'tamam',
            };
            $sayfalar[] = [
                'sayfa' => $n,
                'islem_tablosu_var' => (bool) ($modelden[$n]['has_transaction_table'] ?? false),
                'bulunan_islem' => $bulunan,
                'ayristirilan_islem' => count($bu),
                'inceleme_gereken' => $inceleme + ($reddedilenSayisi[$n] ?? 0),
                'reddedilen' => $reddedilenSayisi[$n] ?? 0,
                'php_islem' => $phpSayfalari[$n] ?? null,
                'metin_var' => $metinVar,
                'model_durumu' => $modelden[$n]['status'] ?? null,
                'durum' => $durum,
            ];
        }

        return [
            'sayfalar' => $sayfalar,
            'pdf_sayfa' => $pdfSayfa,
            'kaynak_metni' => ! $metinsizKaynak,
            'analiz_edilen_sayfa' => count(array_filter($sayfalar, fn (array $s): bool => $s['durum'] !== 'modelde_yok')),
            'sayfa_uyusuyor' => count($modelden) >= $pdfSayfa && array_keys($modelden) === range(1, $pdfSayfa),
            'sorunlu_sayfa' => count(array_filter($sayfalar, fn (array $s): bool => ! in_array($s['durum'], ['tamam'], true) || $s['inceleme_gereken'] > 0)),
        ];
    }
}
