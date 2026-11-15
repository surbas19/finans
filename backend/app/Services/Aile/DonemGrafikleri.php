<?php

namespace App\Services\Aile;

use App\Support\Donem;

/**
 * Faturalar, Kartlar ve Gelirler sayfalarındaki dönem dönem çizgi grafikler için yılın 12 ayı. Yeni hesap yoktur:
 * her ay ButceRaporu'nun gelir / fatura / gider özetlerinden okunur (tek kaynak); burada yalnızca ay ay dizilere dizilir.
 * Tutarlar TL (Kurus::tl çıktıları), dizi 12 elemanlıdır (Ocak..Aralık), verisi olmayan ay 0'dır.
 */
class DonemGrafikleri
{
    public function __construct(private ButceRaporu $rapor) {}

    /**
     * @return array<string, mixed>
     */
    public function yil(int $haneId, int $yil): array
    {
        $etiketler = [];
        $gelirToplam = $faturaToplam = $kartToplam = array_fill(0, 12, 0.0);
        $kisiler = $gelirTurleri = $faturaTurleri = $kartlar = [];

        for ($i = 0; $i < 12; $i++) {
            $donem = sprintf('%04d-%02d', $yil, $i + 1);
            $etiketler[] = Donem::etiket($donem);

            $gelir = $this->rapor->gelirOzeti($haneId, $donem);
            $gelirToplam[$i] = (float) $gelir['toplam'];
            foreach ($gelir['kisiler'] as $kisi) {
                $uye = $kisi['uye']['id'];
                $kisiler[$uye] ??= ['uye_id' => $uye, 'ad' => $kisi['uye']['ad'], 'renk' => $kisi['uye']['renk'], 'toplam' => array_fill(0, 12, 0.0)];
                $kisiler[$uye]['toplam'][$i] = (float) $kisi['toplam'];
                foreach ($kisi['turler'] as $tur) {
                    $id = $tur['gelir_turu_id'];
                    $gelirTurleri[$id] ??= ['gelir_turu_id' => $id, 'ad' => $tur['etiket'], 'toplam' => array_fill(0, 12, 0.0), 'kisiler' => []];
                    $gelirTurleri[$id]['toplam'][$i] = round($gelirTurleri[$id]['toplam'][$i] + (float) $tur['toplam'], 2);
                    $gelirTurleri[$id]['kisiler'][$uye] ??= ['uye_id' => $uye, 'ad' => $kisi['uye']['ad'], 'renk' => $kisi['uye']['renk'], 'toplam' => array_fill(0, 12, 0.0)];
                    $gelirTurleri[$id]['kisiler'][$uye]['toplam'][$i] = (float) $tur['toplam'];
                }
            }

            $fatura = $this->rapor->faturaOzeti($haneId, $donem);
            $faturaToplam[$i] = (float) $fatura['toplam'];
            foreach ($fatura['turler'] as $tur) {
                $id = $tur['fatura_turu_id'];
                $faturaTurleri[$id] ??= ['fatura_turu_id' => $id, 'ad' => $tur['ad'], 'tutar' => array_fill(0, 12, 0.0)];
                $faturaTurleri[$id]['tutar'][$i] = (float) $tur['toplam'];
            }

            foreach ($this->rapor->giderOzeti($haneId, $donem)['kartlar'] as $kart) {
                $id = $kart['kart_id'];
                $kartlar[$id] ??= ['kart_id' => $id, 'ad' => $kart['ad'], 'tutar' => array_fill(0, 12, 0.0)];
                $kartlar[$id]['tutar'][$i] = (float) $kart['toplam'];
                $kartToplam[$i] = round($kartToplam[$i] + (float) $kart['toplam'], 2);
            }
        }

        // Aylık kazanca oran (%): gelir 0 ise null (uydurulmaz)
        $oran = fn (float $tutar, float $gelir): ?float => $gelir > 0 ? round($tutar / $gelir * 100, 1) : null;
        $oranlar = fn (array $tutarlar): array => array_map(fn (float $t, float $g): ?float => $oran($t, $g), $tutarlar, $gelirToplam);

        return [
            'yil' => $yil,
            'etiketler' => $etiketler,
            'gelir' => [
                'toplam' => $gelirToplam,
                'kisiler' => array_values($kisiler),
                'turler' => array_values(array_map(fn (array $t): array => [...$t, 'kisiler' => array_values($t['kisiler'])], $gelirTurleri)),
            ],
            'faturalar' => [
                'toplam' => $faturaToplam,
                'toplam_oran' => $oranlar($faturaToplam),
                'turler' => array_values(array_map(fn (array $t): array => [...$t, 'oran' => $oranlar($t['tutar'])], $faturaTurleri)),
            ],
            'kartlar' => [
                'toplam' => $kartToplam,
                'kartlar' => array_values($kartlar),
            ],
        ];
    }
}
