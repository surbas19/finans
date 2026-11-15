<?php

namespace App\Services\Zekat;

use App\Enums\VarlikTuru;

/**
 * Zekât kurallarının başlangıç değerleri. Kurallar hane başına `zekat_ayarlari`'nda tutulur ve değiştirilebilir;
 * buradakiler fıkhî hüküm değil, başlangıç değeridir.
 */
final class ZekatVarsayilanlari
{
    public const UYARI = 'Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın.';

    public const NISAB_ALTIN_GRAM = 80.18;

    public const NISAB_GUMUS_GRAM = 561.0;

    /** Nisab, "GRAM" (altın) ya da "GUMUS" varlığının güncel fiyatıyla TL'ye çevrilir */
    public const NISAB_VARLIKLARI = ['altin' => 'GRAM', 'gumus' => 'GUMUS'];

    /**
     * Varsayılan ayar: bütün varlık türleri dahil (%100, BES tam tutar), borç düşme kapalı.
     *
     * @return array<string, mixed>
     */
    public static function ayar(): array
    {
        return [
            'nisab_olcutu' => 'altin',
            'nisab_altin_gram' => self::NISAB_ALTIN_GRAM,
            'nisab_gumus_gram' => self::NISAB_GUMUS_GRAM,
            'oran_binde' => 25,
            'tur_kurallari' => self::turKurallari(),
            'borc_dus' => false,
            'borc_kaynaklari' => ['kart' => true, 'fatura' => true, 'elle' => true],
            'yalniz_gunde_nisab' => true,
            'zekat_gunu_turu' => 'hicri',
            'hicri_ay' => 9,
            'hicri_gun' => 1,
            'miladi_ay' => 1,
            'miladi_gun' => 1,
            'hatirlatma_gun' => 30,
        ];
    }

    /**
     * @return array<string, array{dahil: bool, yuzde: int}>
     */
    public static function turKurallari(): array
    {
        $kurallar = [];
        foreach (VarlikTuru::cases() as $tur) {
            $kurallar[$tur->value] = ['dahil' => true, 'yuzde' => 100];
        }

        return $kurallar;
    }
}
