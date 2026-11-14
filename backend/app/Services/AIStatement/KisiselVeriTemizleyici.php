<?php

namespace App\Services\AIStatement;

use App\Support\Metin;

/**
 * Dış servise gitmeden önce ekstre metninden kişisel veriyi çıkarır (en iyi gayretle; tam garanti değil):
 * ad-soyad / adres / kimlik etiketli satırlar silinir; kart numarası, IBAN, TC kimlik no, e-posta ve telefon maskelenir;
 * kart numarasının yanındaki yalnızca harften oluşan kuyruk (kart sahibi adı) atılır. Tutar, tarih ve açıklamalara
 * dokunulmaz. Hem gönderilen metin hem "source_text" doğrulaması bu temizlenmiş metne göre yapılır.
 */
class KisiselVeriTemizleyici
{
    /** Bu sade ifadelerden biriyle başlayan ya da içeren satır tamamen silinir */
    private const SATIR_ETIKETLERI = [
        'AD SOYAD', 'ADI SOYADI', 'KART SAHIBI', 'MUSTERI NUMARASI', 'MUSTERI NO', 'MERSIS', 'VERGI DAIRESI', 'V.D.', 'TC KIMLIK', 'T.C. KIMLIK',
        'KIMLIK NUMARASI', 'IBAN', 'E-POSTA', 'EPOSTA', 'E-MAIL', 'TELEFON', 'ADRES', 'KAPI NO', 'MAHALLESI', 'CADDESI', 'SOKAK NO', 'APT. NO',
    ];

    /**
     * @param  list<string>  $satirlar
     * @return list<string>
     */
    public function temizle(array $satirlar): array
    {
        $sonuc = [];
        foreach ($satirlar as $satir) {
            $sade = Metin::sade($satir);
            if ($this->kisiselSatir($sade)) {
                continue;
            }
            $satir = $this->maskele($satir);
            if ($satir !== '') {
                $sonuc[] = $satir;
            }
        }

        return $sonuc;
    }

    private function kisiselSatir(string $sade): bool
    {
        if (preg_match('/^(SN\.|SAYIN)\s/', $sade) === 1) {
            return true;
        }
        foreach (self::SATIR_ETIKETLERI as $etiket) {
            if (str_contains($sade, $etiket)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saklanacak yapay zekâ çıktısı (JSON görünümü): her metin alanı maskelenir (kart, IBAN, telefon, kimlik, e-posta);
     * anahtarlar ve sayılar değişmez.
     */
    public function maskeleHepsi(mixed $veri): mixed
    {
        if (is_string($veri)) {
            return $this->maskele($veri);
        }

        return is_array($veri) ? array_map(fn (mixed $v): mixed => $this->maskeleHepsi($v), $veri) : $veri;
    }

    public function maskele(string $satir): string
    {
        $satir = (string) preg_replace('/\b\d{4}[ -]?\d{2}\*{2}[ -]?\*{4}[ -]?\d{4}\b/u', '[KART]', $satir);
        $satir = (string) preg_replace('/\b\d{4}[ -]\d{4}[ -]\d{4}[ -]\d{4}\b/u', '[KART]', $satir);
        $satir = (string) preg_replace('/\bTR\d{2}(?: ?\d{4}){5} ?\d{2}\b/iu', '[IBAN]', $satir);
        // Telefon (05xx…, 11 hane) kimlik numarasından önce maskelenir
        $satir = (string) preg_replace('/(?<!\d)(?:\+?90)?0?5\d{9}(?!\d)/u', '[TELEFON]', $satir);
        $satir = (string) preg_replace('/(?<![\d.,])\d{11}(?![\d.,])/u', '[KIMLIK]', $satir);
        $satir = (string) preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[E-POSTA]', $satir);
        // Kart numarasının yanında yalnızca harf olan kuyruk = kart sahibi adı
        $satir = (string) preg_replace('/(\[KART\])\s*(?:\t|\s{2,})\s*[^\d\[\]]+$/u', '$1', $satir);

        return trim($satir);
    }
}
