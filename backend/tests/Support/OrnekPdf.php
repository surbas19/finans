<?php

namespace Tests\Support;

/**
 * Testler için gerçek, küçük PDF yazar (dış araç yok). Her sayfa satırlardan oluşur; satır ya düz metin ya da
 * sütunlara bölünmüş (`[x => metin, …]`: tablo gibi, aynı satırda ayrı metin parçaları). Yalnızca ASCII
 * (Helvetica / WinAnsi). Ayrıca metinsiz (yalnızca çizim) ve şifreli (Encrypt sözlüklü) örnek.
 */
final class OrnekPdf
{
    /**
     * @param  list<list<string|array<int, string>>>  $sayfalar
     * @param  int  $satirAraligi  satırlar arası dikey boşluk (pt); sık tablolu ekstreler için küçültülür
     */
    public static function yaz(array $sayfalar, bool $sifreli = false, int $satirAraligi = 14): string
    {
        $icerikler = [];
        foreach ($sayfalar as $satirlar) {
            $icerik = '';
            $y = 800;
            foreach ($satirlar as $satir) {
                $parcalar = is_array($satir) ? $satir : [40 => $satir];
                foreach ($parcalar as $x => $metin) {
                    $icerik .= sprintf("BT /F1 9 Tf %d %d Td (%s) Tj ET\n", $x, $y, self::kacis($metin));
                }
                $y -= $satirAraligi;
            }
            $icerikler[] = $icerik;
        }

        return self::belge($icerikler, $sifreli);
    }

    /** Metni olmayan (taranmış gibi) PDF: yalnızca bir dikdörtgen çizer */
    public static function metinsiz(): string
    {
        return self::belge(["0.5 g 50 50 400 600 re f\n"], false);
    }

    /**
     * @param  list<string>  $icerikler  sayfa başına içerik akışı
     */
    private static function belge(array $icerikler, bool $sifreli): string
    {
        $nesneler = [];
        $sayfaNesneleri = [];
        $sonraki = 4; // 1 katalog, 2 sayfalar, 3 yazı tipi
        foreach ($icerikler as $icerik) {
            $icerikNo = $sonraki++;
            $sayfaNo = $sonraki++;
            $nesneler[$icerikNo] = '<< /Length '.strlen($icerik)." >>\nstream\n".$icerik.'endstream';
            $nesneler[$sayfaNo] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$icerikNo} 0 R >>";
            $sayfaNesneleri[] = "{$sayfaNo} 0 R";
        }
        $nesneler[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $nesneler[2] = '<< /Type /Pages /Kids ['.implode(' ', $sayfaNesneleri).'] /Count '.count($sayfaNesneleri).' >>';
        $nesneler[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        if ($sifreli) {
            $nesneler[$sonraki] = '<< /Filter /Standard /V 1 /R 2 /O (aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa) /U (bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb) /P -44 >>';
        }
        ksort($nesneler);

        $pdf = "%PDF-1.4\n";
        $konumlar = [];
        foreach ($nesneler as $no => $govde) {
            $konumlar[$no] = strlen($pdf);
            $pdf .= "{$no} 0 obj\n{$govde}\nendobj\n";
        }
        $xref = strlen($pdf);
        $adet = max(array_keys($nesneler)) + 1;
        $pdf .= "xref\n0 {$adet}\n0000000000 65535 f \n";
        for ($i = 1; $i < $adet; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $konumlar[$i] ?? 0);
        }
        $sifre = $sifreli ? ' /Encrypt '.max(array_keys($nesneler)).' 0 R /ID [<0123456789abcdef0123456789abcdef> <0123456789abcdef0123456789abcdef>]' : '';
        $pdf .= "trailer\n<< /Size {$adet} /Root 1 0 R{$sifre} >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private static function kacis(string $metin): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $metin);
    }
}
