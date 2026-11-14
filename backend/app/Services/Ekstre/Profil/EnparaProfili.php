<?php

namespace App\Services\Ekstre\Profil;

/**
 * Enpara (QNB). Satır: `21/10/2026\tÖdeme - Enpara.com Cep Şubesi\t- 2.081,00 TL` (ödemede eksi, boşluklu);
 * etiketler "Ekstre tarihi", "Ekstre borcu", "Minimum ödeme tutarı"; tablo "İşlem tarihi Açıklama" ile başlar.
 */
class EnparaProfili extends EkstreProfili
{
    public function ad(): string
    {
        return 'enpara';
    }

    public function etiket(): string
    {
        return 'Enpara';
    }

    public function parmakIzleri(): array
    {
        return ['ENPARA'];
    }

    public function alanEtiketleri(): array
    {
        return [
            'asgari_odeme' => ['MINIMUM ODEME TUTARI'],
            'onceki_borc' => ['ONCEKI EKSTRE BAKIYENIZ'],
        ];
    }

    public function tabloSinirliMi(): bool
    {
        return true;
    }

    public function tabloBasi(string $sade): bool
    {
        return str_starts_with($sade, 'ISLEM TARIHI ACIKLAMA');
    }

    public function tabloSonu(string $sade): bool
    {
        return str_starts_with($sade, '* BSMV') || str_starts_with($sade, 'BIR SONRAKI EKSTRENIZIN');
    }
}
