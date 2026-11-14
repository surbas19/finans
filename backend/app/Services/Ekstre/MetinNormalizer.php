<?php

namespace App\Services\Ekstre;

use App\Support\Donem;
use App\Support\Metin;

/**
 * PDF'ten gelen metni bankadan bağımsız ortak biçime getirir (ayrıştırıcıdan önce, her profil için):
 * - Yanlış kod sayfası: CP1254 (Türkçe) metin Latin-1 gibi çözülünce `Ýþlem`, `Kasým` olur; bu altı karakter Türkçede
 *   kullanılmadığı için koşulsuz düzeltilir (Ý→İ ý→ı þ→ş Þ→Ş ð→ğ Ð→Ğ).
 * - Ay adlı tarih: `22 Ekim 2026` → `22.10.2026` (kısaltmalar da: `22 Eki 2026`).
 */
class MetinNormalizer
{
    private const KOD_SAYFASI = ['Ý' => 'İ', 'ý' => 'ı', 'þ' => 'ş', 'Þ' => 'Ş', 'ð' => 'ğ', 'Ð' => 'Ğ'];

    private const KISALTMALAR = ['OCA' => 1, 'SUB' => 2, 'MAR' => 3, 'NIS' => 4, 'MAY' => 5, 'HAZ' => 6, 'TEM' => 7, 'AGU' => 8, 'EYL' => 9, 'EKI' => 10, 'KAS' => 11, 'ARA' => 12];

    /**
     * @param  list<list<string>>  $sayfalar
     * @return list<list<string>>
     */
    public function normalize(array $sayfalar): array
    {
        return array_map(fn (array $sayfa): array => array_map(fn (string $s): string => $this->satir($s), $sayfa), $sayfalar);
    }

    public function satir(string $satir): string
    {
        return $this->ayAdlari(strtr($satir, self::KOD_SAYFASI));
    }

    /** `22 Ekim 2026`, `22 EKİM 2026`, `22 Eki 2026` → `22.10.2026` */
    public function ayAdlari(string $metin): string
    {
        return (string) preg_replace_callback(
            '/(?<![\d.])(\d{1,2})\s+(\p{L}{3,8})\.?\s+(\d{4})(?!\d)/u',
            function (array $e): string {
                $ay = Donem::ayNumarasi($e[2]) ?? self::KISALTMALAR[Metin::sade($e[2])] ?? null;

                return $ay === null ? $e[0] : sprintf('%02d.%02d.%s', (int) $e[1], $ay, $e[3]);
            },
            $metin,
        );
    }
}
