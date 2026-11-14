<?php

namespace App\Services\Ekstre\Profil;

use App\Support\Kurus;

/**
 * Yapı Kredi (World). Satır: `28.09.2026 HEPSIPAY ... TR\t211,66\t4` (sağda puan sütunu); alacak `+8.085,68`;
 * taksitli satırda sağda `10.546,80 / 6` (kalan tutar / kalan taksit) ve altında
 * `15.820,17 TL'lik işlemin 3 / 9 taksidi`. Tablo "İşlem Tarihi İşlemler" ile başlar, "TOPLAM" ile biter.
 */
class YapiKrediProfili extends EkstreProfili
{
    private const TUTAR = '(\d{1,3}(?:\.\d{3})*,\d{2})';

    public function ad(): string
    {
        return 'yapikredi';
    }

    public function etiket(): string
    {
        return 'Yapı Kredi';
    }

    public function parmakIzleri(): array
    {
        return ['YAPI VE KREDI BANKASI', 'WORLDPUAN', 'YAPIKREDI'];
    }

    public function alanEtiketleri(): array
    {
        return [
            'asgari_odeme' => ['ODENMESI GEREKEN ASGARI TUTAR'],
            'onceki_borc' => ['ONCEKI DONEM HESAP OZETI BORCU'],
        ];
    }

    public function tabloSinirliMi(): bool
    {
        return true;
    }

    public function tabloBasi(string $sade): bool
    {
        return str_starts_with($sade, 'ISLEM TARIHI ISLEMLER');
    }

    public function tabloSonu(string $sade): bool
    {
        return str_starts_with($sade, 'TOPLAM') || str_starts_with($sade, 'PUAN OZETI');
    }

    public function sondakiSutunlar(): array
    {
        return [
            '/\t(?<kalan>'.self::TUTAR.') \/ (?<kalan_taksit>\d{1,2})$/',
            '/\t[-+]?\d{1,5}$/', // puan
        ];
    }

    public function devamSatiri(string $sade): ?array
    {
        if (preg_match('/^'.self::TUTAR.' TL\'?LIK ISLEMIN (\d{1,2}) \/ (\d{1,2}) TAKSIDI/', $sade, $e) && (int) $e[3] >= 2 && (int) $e[2] <= (int) $e[3]) {
            return ['taksit_no' => (int) $e[2], 'taksit_sayisi' => (int) $e[3], 'taksit_toplam' => Kurus::decimal(Kurus::kurus(str_replace(['.', ','], ['', '.'], $e[1])))];
        }

        return null;
    }

    public function toplamSatiri(string $sade): ?string
    {
        if (preg_match('/^TOPLAM\s+'.self::TUTAR.'/', $sade, $e)) {
            return Kurus::decimal(Kurus::kurus(str_replace(['.', ','], ['', '.'], $e[1])));
        }

        return null;
    }
}
