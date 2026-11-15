<?php

namespace App\Services\AIStatement;

/**
 * Sohbet arayüzlerinden kopyalanan JSON'un **taşıma / biçim artıklarını** onarır; değer düzeltmez, tahmin yapmaz.
 * Onarılanlar: markdown kaçışları (`\*`, `\_`, `\#`… JSON'da geçersiz), satır devamı (`\` + satır sonu), HTML boşluk
 * varlıkları (`&#x20;`, `&nbsp;`), NBSP ve sıfır genişlikli karakterler. Geçerli JSON kaçışları (`\" \\ \/ \b \f \n \r \t
 * \uXXXX`) olduğu gibi kalır. Ayrıca onarılamayan metinde ilk hatanın satır / sütununu ve nedenini söyler (içerik
 * yankılanmaz, loglanmaz).
 */
final class JsonOnarici
{
    /**
     * @return array{metin: string, duzeltme: ?string}
     */
    public function onar(string $metin): array
    {
        $say = ['kacis' => 0, 'devam' => 0, 'html' => 0, 'gorunmez' => 0];
        $metin = preg_replace_callback('/&#(?:x20|32);|&nbsp;/i', function () use (&$say): string {
            $say['html']++;

            return ' ';
        }, $metin) ?? $metin;
        $metin = preg_replace_callback('/\x{00A0}|[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', function (array $m) use (&$say): string {
            $say['gorunmez']++;

            return $m[0] === "\u{00A0}" ? ' ' : '';
        }, $metin) ?? $metin;

        $cikti = '';
        $icinde = false;
        $uzun = strlen($metin);
        for ($i = 0; $i < $uzun; $i++) {
            $c = $metin[$i];
            if (! $icinde) {
                if ($c === '"') {
                    $icinde = true;
                } elseif ($c === '\\' && preg_match('/\G[ \t]*(?:\R|\z)/', $metin, $m, 0, $i + 1) === 1) {
                    $say['devam']++; // satır sonundaki `\` (satır devamı) atılır

                    continue;
                }
                $cikti .= $c;

                continue;
            }
            if ($c === '"') {
                $icinde = false;
                $cikti .= $c;

                continue;
            }
            if ($c !== '\\') {
                $cikti .= $c;

                continue;
            }
            $sonraki = $metin[$i + 1] ?? null;
            if ($sonraki === null) {
                $cikti .= $c;

                continue;
            }
            if ($sonraki === 'u' && preg_match('/\Gu[0-9a-fA-F]{4}/', $metin, $m, 0, $i + 1) === 1) {
                $cikti .= '\\'.$m[0];
                $i += strlen($m[0]);

                continue;
            }
            if (str_contains('"\\/bfnrt', $sonraki)) {
                $cikti .= $c.$sonraki;
                $i++;

                continue;
            }
            if ($sonraki === "\n" || $sonraki === "\r") {
                $say['devam']++; // tırnak içinde satır devamı: ters eğik çizgi ve satır sonu atılır
                $i += ($sonraki === "\r" && ($metin[$i + 2] ?? '') === "\n") ? 2 : 1;

                continue;
            }
            // Markdown kaçışı (`\*` …): ters eğik çizgi atılır, karakter kalır (sonraki turda olduğu gibi kopyalanır)
            $say['kacis']++;
        }

        $toplam = array_sum($say);
        if ($toplam === 0) {
            return ['metin' => $cikti, 'duzeltme' => null];
        }
        $neler = array_filter([
            $say['kacis'] > 0 ? 'ters eğik çizgili kaçışlar (\\*, \\_ …)' : null,
            $say['devam'] > 0 ? 'satır sonu ters eğik çizgileri' : null,
            $say['html'] > 0 ? 'HTML boşluklar (&#x20;)' : null,
            $say['gorunmez'] > 0 ? 'görünmez karakterler' : null,
        ]);

        return [
            'metin' => $cikti,
            'duzeltme' => "JSON'daki biçim artıkları otomatik temizlendi (".implode(', ', $neler).'); değerler değişmedi.',
        ];
    }

    /**
     * Onarılamayan JSON'da ilk hatanın yeri ve nedeni ("satır 12, sütun 17: …"); bulunamazsa null.
     */
    public function hataKonumu(string $metin): ?string
    {
        $uzun = strlen($metin);
        $satir = 1;
        $satirBasi = 0;
        $icinde = false;
        $yigin = [];
        $kapandi = false; // en üst değer kapandı
        $konum = function (int $i) use (&$satir, &$satirBasi, $metin): string {
            $sutun = mb_strlen((string) substr($metin, $satirBasi, $i - $satirBasi)) + 1;

            return "satır {$satir}, sütun {$sutun}";
        };
        $sonAnlamli = '';
        for ($i = 0; $i < $uzun; $i++) {
            $c = $metin[$i];
            if ($c === "\n") {
                if ($icinde) {
                    return $konum($i).': tırnak içinde satır sonu var (metin tırnağı kapatılmamış ya da yanıt kesilmiş olabilir)';
                }
                $satir++;
                $satirBasi = $i + 1;

                continue;
            }
            if ($icinde) {
                if ($c === '\\') {
                    $s = $metin[$i + 1] ?? '';
                    if ($s === 'u' ? preg_match('/\Gu[0-9a-fA-F]{4}/', $metin, $m, 0, $i + 1) !== 1 : ! str_contains('"\\/bfnrt', $s) || $s === '') {
                        return $konum($i).': geçersiz ters eğik çizgi (\\'.($s !== '' && ord($s) >= 32 ? $s : '').') — metinde ters eğik çizgi kullanılmamalı';
                    }
                    $i++;
                } elseif ($c === '"') {
                    $icinde = false;
                    $sonAnlamli = '"';
                }

                continue;
            }
            if (ctype_space($c)) {
                continue;
            }
            if ($kapandi) {
                return $konum($i).": JSON'dan sonra fazladan metin var";
            }
            if ($c === '"') {
                $icinde = true;
            } elseif ($c === '{' || $c === '[') {
                $yigin[] = $c;
                $sonAnlamli = $c;
            } elseif ($c === '}' || $c === ']') {
                if ($sonAnlamli === ',') {
                    return $konum($i).': sondaki virgül (son öğeden sonra virgül olmamalı)';
                }
                $beklenen = $c === '}' ? '{' : '[';
                if (array_pop($yigin) !== $beklenen) {
                    return $konum($i).": beklenmeyen '{$c}' (parantezler eşleşmiyor)";
                }
                $sonAnlamli = $c;
                if ($yigin === []) {
                    $kapandi = true;
                }
            } else {
                $sonAnlamli = $c;
            }
        }
        if ($icinde) {
            return 'yanıt yarım kalmış: tırnak kapatılmamış (yapay zekânın çıktısı kesilmiş olabilir)';
        }
        if ($yigin !== []) {
            return 'yanıt yarım kalmış: '.count($yigin).' açık parantez kapatılmamış (yapay zekânın çıktısı kesilmiş olabilir)';
        }

        return null;
    }
}
