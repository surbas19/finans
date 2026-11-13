<?php

namespace App\Services\Ekstre;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDF → sayfa sayfa metin satırları (smalot/pdfparser, saf PHP; sunucuda `proc_open` kapalı olduğu için pdftotext
 * kullanılamaz). Metin parçaları konumlarına göre satırlara toplanır: aynı yükseklikteki parçalar soldan sağa,
 * aralarında sütun boşluğu varsa sekmeyle. İçerik loglanmaz; hata mesajları içeriksizdir.
 */
class PdfMetinOkuyucu
{
    /** Aynı satır sayılan dikey fark (pt) */
    private const SATIR_PAYI = 2.5;

    /** Sekmeyle ayrılan yatay boşluk (pt): tablo sütunları */
    private const SUTUN_BOSLUGU = 12;

    /**
     * @return array{sayfalar: list<list<string>>, sayfa_sayisi: int}
     *
     * @throws EkstreOkunamadi
     */
    public function oku(string $icerik): array
    {
        $ayar = new Config;
        $ayar->setRetainImageContent(false);
        try {
            $belge = (new Parser([], $ayar))->parseContent($icerik);
            $sayfalar = [];
            foreach ($belge->getPages() as $sayfa) {
                $sayfalar[] = $this->satirlar($sayfa);
            }
        } catch (Throwable $e) {
            throw str_contains($e->getMessage(), 'Secured pdf') ? EkstreOkunamadi::sifreli() : EkstreOkunamadi::bozuk();
        }

        $metin = implode("\n", array_merge(...($sayfalar ?: [[]])));
        if (! preg_match('/\d/', $metin) || mb_strlen(trim($metin)) < 20) {
            throw EkstreOkunamadi::metinsiz();
        }

        return ['sayfalar' => $sayfalar, 'sayfa_sayisi' => count($sayfalar)];
    }

    /**
     * @return list<string>
     */
    private function satirlar(object $sayfa): array
    {
        try {
            $parcalar = $sayfa->getDataTm();
        } catch (Throwable) {
            $parcalar = [];
        }
        if ($parcalar === []) {
            // Konum yoksa düz metin (sekme ayrımı smalot'tan)
            return array_values(array_filter(array_map('trim', explode("\n", (string) $sayfa->getText())), fn (string $s): bool => $s !== ''));
        }

        // [x, y, metin]; yukarıdan aşağı, soldan sağa
        $ogeler = [];
        foreach ($parcalar as [$matris, $metin]) {
            $metin = trim((string) $metin);
            if ($metin !== '') {
                $ogeler[] = [(float) $matris[4], (float) $matris[5], $metin];
            }
        }
        usort($ogeler, fn (array $a, array $b): int => abs($a[1] - $b[1]) > self::SATIR_PAYI ? $b[1] <=> $a[1] : $a[0] <=> $b[0]);

        $satirlar = [];
        $grup = [];
        foreach ($ogeler as $oge) {
            if ($grup !== [] && abs($oge[1] - $grup[0][1]) > self::SATIR_PAYI) {
                $satirlar[] = $this->birlestir($grup);
                $grup = [];
            }
            $grup[] = $oge;
        }
        if ($grup !== []) {
            $satirlar[] = $this->birlestir($grup);
        }

        return $satirlar;
    }

    /**
     * @param  list<array{0: float, 1: float, 2: string}>  $grup
     */
    private function birlestir(array $grup): string
    {
        usort($grup, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $satir = '';
        $oncekiSon = null;
        foreach ($grup as [$x, , $metin]) {
            if ($satir !== '') {
                // Yaklaşık genişlik: karakter başına 5 pt (9 pt yazıda); büyük boşluk sütun ayrımıdır
                $satir .= ($oncekiSon !== null && $x - $oncekiSon > self::SUTUN_BOSLUGU) ? "\t" : ' ';
            }
            $satir .= $metin;
            $oncekiSon = $x + mb_strlen($metin) * 5;
        }

        return $satir;
    }
}
