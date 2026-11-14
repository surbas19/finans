<?php

namespace App\Services\Ekstre;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Form;
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
     * @param  bool  $metinsizKabul  taranmış (metinsiz) PDF istisna yerine `metinsiz: true` ile döner (yapay zekâ
     *                               görüntüden okuyabilir); şifreli / bozuk PDF yine reddedilir
     * @return array{sayfalar: list<list<string>>, sayfa_sayisi: int, metinsiz: bool}
     *
     * @throws EkstreOkunamadi
     */
    public function oku(string $icerik, bool $metinsizKabul = false): array
    {
        $sayfalar = array_map(
            fn (array $satirlar): array => array_map(fn (array|string $grup): string => is_string($grup) ? $grup : $this->birlestir($grup), $satirlar),
            $this->sayfaGruplari($icerik),
        );

        $metin = implode("\n", array_merge(...($sayfalar ?: [[]])));
        $metinsiz = ! preg_match('/\d/', $metin) || mb_strlen(trim($metin)) < 20;
        if ($metinsiz && ! $metinsizKabul) {
            throw EkstreOkunamadi::metinsiz();
        }

        return ['sayfalar' => $sayfalar, 'sayfa_sayisi' => count($sayfalar), 'metinsiz' => $metinsiz];
    }

    /**
     * Sayfa sayfa satırlar, her satır konumlu parçalarıyla (soldan sağa `[x, metin]`): sütun düzenini görmek ve test
     * verisi üretmek için. Konumu olmayan (düz metin yedeği) satır string döner.
     *
     * @return list<list<list<array{0: float, 1: string}>|string>>
     *
     * @throws EkstreOkunamadi
     */
    public function konumluSatirlar(string $icerik): array
    {
        return array_map(fn (array $satirlar): array => array_map(
            fn (array|string $grup): array|string => is_string($grup) ? $grup : array_map(fn (array $o): array => [$o[0], $o[2]], $this->sirala($grup)),
            $satirlar,
        ), $this->sayfaGruplari($icerik));
    }

    /**
     * Sayfa başına satır grupları (her grup bir satırın konumlu parçaları; düz metin yedeğinde satır string).
     *
     * @return list<list<list<array{0: float, 1: float, 2: string}>|string>>
     *
     * @throws EkstreOkunamadi
     */
    private function sayfaGruplari(string $icerik): array
    {
        $ayar = new Config;
        $ayar->setRetainImageContent(false);
        try {
            $belge = (new Parser([], $ayar))->parseContent($icerik);
            $sayfalar = [];
            foreach ($belge->getPages() as $sayfa) {
                // Sayfanın kendi konumlu metni + içine çizilen formların konumlu metni (aynı form iki adla geçebilir:
                // bir kez). İkisi de yoksa smalot'un düz metni (formları da içerir; o yüzden yalnızca yedek).
                $satirlar = $this->gruplar($this->parcalar($sayfa));
                $okunan = [];
                foreach ($sayfa->getXObjects() as $nesne) {
                    if (! $nesne instanceof Form) {
                        continue;
                    }
                    $form = FormSayfasi::formdan($nesne, $belge, $ayar);
                    if (isset($okunan[$form->ozet()])) {
                        continue;
                    }
                    $okunan[$form->ozet()] = true;
                    $satirlar = [...$satirlar, ...$this->gruplar($this->parcalar($form))];
                }
                if ($satirlar === []) {
                    $satirlar = array_values(array_filter(array_map('trim', explode("\n", (string) $sayfa->getText())), fn (string $s): bool => $s !== ''));
                }
                $sayfalar[] = $satirlar;
            }
        } catch (Throwable $e) {
            throw str_contains($e->getMessage(), 'Secured pdf') ? EkstreOkunamadi::sifreli() : EkstreOkunamadi::bozuk();
        }

        return $sayfalar;
    }

    /**
     * Konumlu metin parçaları: [x, y, metin]; okunamazsa boş.
     *
     * @return list<array{0: float, 1: float, 2: string}>
     */
    private function parcalar(object $sayfa): array
    {
        try {
            $parcalar = $sayfa->getDataTm();
        } catch (Throwable) {
            return [];
        }
        $ogeler = [];
        foreach ($parcalar as [$matris, $metin]) {
            $metin = trim((string) $metin);
            if ($metin !== '') {
                $ogeler[] = [(float) $matris[4], (float) $matris[5], $metin];
            }
        }

        return $ogeler;
    }

    /**
     * Parçaları satır gruplarına toplar: yukarıdan aşağı (her grup bir satırın parçaları).
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $ogeler
     * @return list<list<array{0: float, 1: float, 2: string}>>
     */
    private function gruplar(array $ogeler): array
    {
        usort($ogeler, fn (array $a, array $b): int => abs($a[1] - $b[1]) > self::SATIR_PAYI ? $b[1] <=> $a[1] : $a[0] <=> $b[0]);

        $satirlar = [];
        $grup = [];
        foreach ($ogeler as $oge) {
            if ($grup !== [] && abs($oge[1] - $grup[0][1]) > self::SATIR_PAYI) {
                $satirlar[] = $grup;
                $grup = [];
            }
            $grup[] = $oge;
        }
        if ($grup !== []) {
            $satirlar[] = $grup;
        }

        return $satirlar;
    }

    /**
     * @param  list<array{0: float, 1: float, 2: string}>  $grup
     * @return list<array{0: float, 1: float, 2: string}>
     */
    private function sirala(array $grup): array
    {
        usort($grup, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $grup;
    }

    /**
     * @param  list<array{0: float, 1: float, 2: string}>  $grup
     */
    private function birlestir(array $grup): string
    {
        $satir = '';
        $oncekiSon = null;
        foreach ($this->sirala($grup) as [$x, , $metin]) {
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
