<?php

namespace App\Services\Ekstre;

use App\Support\Kurus;

/**
 * Ekstre mutabakatı (finansçının yaptığı kontroller), kuruş hassasiyetiyle:
 * - Borç denklemi: önceki dönem borcu − ödemeler − iadeler + harcamalar + faiz / ücretler + nakit avans = dönem borcu.
 * - Garanti özet satırı: ekstrenin kendi toplamları (harcama, faiz, ödeme) satırlardan çıkanla aynı mı.
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
        $t = ['borc' => 0, 'ucret' => 0, 'avans' => 0, 'odeme' => 0, 'iade' => 0];
        foreach ($satirlar as $s) {
            if (($s['tutar'] ?? null) === null) {
                continue;
            }
            $kurus = Kurus::kurus($s['tutar']);
            match ($s['tur']) {
                'odeme' => $t['odeme'] += $kurus,
                'iade' => $t['iade'] += $kurus,
                'ucret' => $t['ucret'] += $kurus,
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
        ];

        $kontroller = [];
        $borc = $alanlar['donem_borcu'] ?? null;
        $onceki = $ozet['onceki_borc'] ?? null;
        if ($borc !== null && $onceki !== null) {
            $beklenen = Kurus::kurus($borc);
            $bulunan = Kurus::kurus($onceki) - $t['odeme'] - $t['iade'] + $t['borc'] + $t['ucret'] + $t['avans'];
            $kontroller[] = $this->kontrol('borc_denklemi', 'Önceki borç − ödemeler − iadeler + harcamalar + faiz / ücretler = dönem borcu', $beklenen, $bulunan);
        } else {
            $kontroller[] = ['ad' => 'borc_denklemi', 'etiket' => 'Borç denklemi', 'durum' => 'kontrol_edilemedi', 'neden' => $borc === null ? 'Dönem borcu okunamadı.' : 'Önceki dönem borcu okunamadı.'];
        }
        // Ekstrenin kendi özet toplamları (varsa) satırlardan çıkanla karşılaştırılır
        foreach ([['harcama_toplami', 'Dönem harcamaları', $t['borc']], ['faiz_toplami', 'Faiz ve ücretler', $t['ucret'] + $t['avans']], ['odeme_toplami', 'Ödemeler', $t['odeme']]] as [$anahtar, $etiket, $satirToplami]) {
            if (($ozet[$anahtar] ?? null) !== null) {
                $kontroller[] = $this->kontrol($anahtar, $etiket.' (ekstre özeti = satırlar)', Kurus::kurus($ozet[$anahtar]), $satirToplami);
            }
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
