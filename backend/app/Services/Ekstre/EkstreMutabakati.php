<?php

namespace App\Services\Ekstre;

use App\Support\Kurus;

/**
 * Ekstre mutabakatı (finansçının yaptığı kontroller), kuruş hassasiyetiyle:
 * - Borç denklemi: önceki dönem borcu − ödemeler − iadeler − bonus + harcamalar + faiz / ücret / vergi + nakit avans =
 *   dönem borcu.
 * - Ekstrenin kendi özet toplamları (varsa): dönem hareketi (harcama + faiz / ücret toplamı; bankalar iadeyi, satır
 *   faizini farklı kalemlere yazabildiği için birlikte) = satırlarda harcama + faiz / ücret / vergi + avans − iade − bonus;
 *   ödemeler = ödeme satırları.
 * - Taksit: toplam tutar, tutar × taksit sayısına yaklaşık eşit mi (faizli taksitte fark olabilir: uyarı değil bilgi).
 * Uyuşmazlık ekstreyi engellemez; "tutmuyor: X TL fark" olarak incelemede görünür. Okunamayan değerle kontrol yapılmaz
 * ("kontrol edilemedi"); uydurulmaz.
 */
class EkstreMutabakati
{
    /**
     * @param  array<string, string|null>  $alanlar  dönem alanları (donem_borcu…)
     * @param  array<string, string|null>  $ozet  ayrıştırıcının ek toplamları (onceki_borc, harcama_toplami, toplam_satiri…)
     * @param  list<array<string, mixed>>  $satirlar
     * @return array{durum: string, fark: string|null, kontroller: list<array<string, mixed>>, toplamlar: array<string, string>}
     */
    public function denetle(array $alanlar, array $ozet, array $satirlar): array
    {
        $t = ['borc' => 0, 'ucret' => 0, 'avans' => 0, 'odeme' => 0, 'iade' => 0, 'bonus' => 0];
        foreach ($satirlar as $s) {
            if (($s['tutar'] ?? null) === null) {
                continue;
            }
            $kurus = Kurus::kurus($s['tutar']);
            match ($s['tur']) {
                'odeme' => $t['odeme'] += $kurus,
                'iade' => $t['iade'] += $kurus,
                'bonus' => $t['bonus'] += $kurus,
                'ucret', 'faiz', 'vergi' => $t['ucret'] += $kurus,
                'nakit_avans' => $t['avans'] += $kurus,
                default => $t['borc'] += $kurus, // harcama, transfer
            };
        }
        $toplamlar = [
            'harcama' => Kurus::decimal($t['borc']),
            'faiz_ucret' => Kurus::decimal($t['ucret']),
            'nakit_avans' => Kurus::decimal($t['avans']),
            'odeme' => Kurus::decimal($t['odeme']),
            'iade' => Kurus::decimal($t['iade']),
            'bonus' => Kurus::decimal($t['bonus']),
        ];

        $kontroller = [];
        $borc = $alanlar['donem_borcu'] ?? null;
        $onceki = $ozet['onceki_borc'] ?? null;
        if ($borc !== null && $onceki !== null) {
            $beklenen = Kurus::kurus($borc);
            $bulunan = Kurus::kurus($onceki) - $t['odeme'] - $t['iade'] - $t['bonus'] + $t['borc'] + $t['ucret'] + $t['avans'];
            $kontroller[] = $this->kontrol('borc_denklemi', 'Önceki borç − ödemeler − iadeler − bonus + harcamalar + faiz / ücret / vergi = dönem borcu', $beklenen, $bulunan);
        } else {
            $kontroller[] = ['ad' => 'borc_denklemi', 'etiket' => 'Borç denklemi', 'durum' => 'kontrol_edilemedi', 'neden' => $borc === null ? 'Dönem borcu okunamadı.' : 'Önceki dönem borcu okunamadı.'];
        }
        // Ekstrenin kendi özet toplamları (varsa) satırlardan çıkanla karşılaştırılır
        if (($ozet['harcama_toplami'] ?? null) !== null || ($ozet['faiz_toplami'] ?? null) !== null) {
            $ekstre = Kurus::kurus($ozet['harcama_toplami'] ?? '0') + Kurus::kurus($ozet['faiz_toplami'] ?? '0');
            $satirlardan = $t['borc'] + $t['ucret'] + $t['avans'] - $t['iade'] - $t['bonus'];
            $kontroller[] = $this->kontrol('donem_hareketi', 'Dönem harcamaları + faiz / ücretler (ekstre özeti = satırlar, iadeler düşülmüş)', $ekstre, $satirlardan);
        }
        if (($ozet['odeme_toplami'] ?? null) !== null) {
            $kontroller[] = $this->kontrol('odeme_toplami', 'Ödemeler (ekstre özeti = satırlar)', Kurus::kurus($ozet['odeme_toplami']), $t['odeme']);
        }

        $okunamayan = array_filter($kontroller, fn (array $k): bool => $k['durum'] === 'kontrol_edilemedi');
        $tutmayan = array_values(array_filter($kontroller, fn (array $k): bool => $k['durum'] === 'tutmuyor'));
        $durum = $tutmayan !== [] ? 'tutmuyor' : (count($okunamayan) === count($kontroller) ? 'kontrol_edilemedi' : 'tutuyor');

        return [
            'durum' => $durum,
            'fark' => $tutmayan !== [] ? $tutmayan[0]['fark'] : null,
            'kontroller' => $kontroller,
            'toplamlar' => $toplamlar,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kontrol(string $ad, string $etiket, int $beklenen, int $bulunan): array
    {
        $fark = $bulunan - $beklenen;

        return [
            'ad' => $ad,
            'etiket' => $etiket,
            'durum' => $fark === 0 ? 'tutuyor' : 'tutmuyor',
            'beklenen' => Kurus::decimal($beklenen),
            'bulunan' => Kurus::decimal($bulunan),
            'fark' => Kurus::decimal($fark),
        ];
    }
}
