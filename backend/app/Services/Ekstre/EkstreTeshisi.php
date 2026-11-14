<?php

namespace App\Services\Ekstre;

/**
 * "Neden okunamadı?" teşhisi: 0 satır çıktığında ya da banka tanınmadığında, ayrıştırıcının neye takıldığını söyler.
 * İçerik sızmaz: örnek satırlarda bütün rakamlar `#`'ye çevrilir (tutar, tarih, kart / hesap no), uzun satırlar kesilir;
 * hiçbir şey loglanmaz, yalnızca ekstre kaydındaki `denetim.teshis`'te saklanır (yalnızca hane üyeleri görür).
 */
class EkstreTeshisi
{
    private const TARIHLI = '/^\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?\s+\S/u';

    public function __construct(private MetinNormalizer $normalizer) {}

    /**
     * @param  list<list<string>>  $sayfalar  PDF'ten okunan ham sayfalar
     * @param  array{profil?: string, satirlar: list<array<string, mixed>>}  $sonuc  ayrıştırıcı çıktısı
     * @return array<string, mixed>|null gerekmiyorsa null (satır var ve banka tanındı)
     */
    public function olustur(array $sayfalar, array $sonuc): ?array
    {
        $profil = $sonuc['profil'] ?? 'genel';
        $satirSayisi = count($sonuc['satirlar']);
        if ($satirSayisi > 0 && $profil !== 'genel') {
            return null;
        }

        $normal = $this->normalizer->normalize($sayfalar);
        $hepsi = array_merge(...($normal ?: [[]]));
        $okunanlar = array_map(fn (array $s): string => trim((string) $s['ham']), $sonuc['satirlar']);
        $tarihli = array_values(array_filter($hepsi, fn (string $s): bool => preg_match(self::TARIHLI, trim($s)) === 1));
        $okunmayanTarihli = array_values(array_filter($tarihli, fn (string $s): bool => ! in_array(trim($s), $okunanlar, true)));
        $tutarli = array_values(array_filter($hepsi, fn (string $s): bool => preg_match('/\d[\d.]*,\d{2}/', $s) === 1));

        $nedenler = [];
        if ($profil === 'genel') {
            $nedenler[] = 'Banka tanınamadı; genel kurallarla okundu. Bu bankanın düzeni için profil eklenmesi gerekebilir.';
        }
        if ($satirSayisi === 0) {
            if ($tarihli === []) {
                $nedenler[] = $tutarli === []
                    ? 'PDF\'te tarihli ya da tutarlı hiçbir satır bulunamadı (işlem tablosu görüntü olabilir).'
                    : 'Tutarlı satırlar var ama hiçbiri tarihle başlamıyor: tarih biçimi desteklenmiyor olabilir (gg.aa.yyyy, gg/aa/yyyy ve "22 Ekim 2026" desteklenir).';
            } else {
                $nedenler[] = count($tarihli).' tarihli satır var ama hiçbirinde okunabilir tutar / açıklama bulunamadı.';
            }
        } elseif ($okunmayanTarihli !== []) {
            $nedenler[] = count($okunmayanTarihli).' tarihli satır işlem sayılmadı; eksik satır olabilir, PDF\'le karşılaştırın.';
        }

        $ornekKaynagi = $okunmayanTarihli !== [] ? $okunmayanTarihli : ($tutarli !== [] ? $tutarli : $hepsi);

        return [
            'banka_taninmadi' => $profil === 'genel',
            'sayfa_sayisi' => count($sayfalar),
            'metin_satiri' => count($hepsi),
            'tarihli_satir' => count($tarihli),
            'okunan_satir' => $satirSayisi,
            'okunmayan_tarihli_satir' => count($okunmayanTarihli),
            'nedenler' => $nedenler,
            'ornekler' => array_map(fn (string $s): string => self::maskele($s), array_slice($ornekKaynagi, 0, 3)),
        ];
    }

    /** Rakamları `#` yapar, boşlukları sadeleştirir, 80 karaktere keser: harfler (açıklama) kalır, tutar / tarih / numara kalmaz */
    public static function maskele(string $satir): string
    {
        $maske = (string) preg_replace('/\d/u', '#', $satir);
        $maske = (string) preg_replace('/\s+/u', ' ', $maske);

        return mb_substr(trim($maske), 0, 80);
    }
}
