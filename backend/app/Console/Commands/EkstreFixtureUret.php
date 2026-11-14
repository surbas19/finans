<?php

namespace App\Console\Commands;

use App\Services\AIStatement\KisiselVeriTemizleyici;
use App\Services\Ekstre\EkstreAyristirici;
use App\Services\Ekstre\EkstreMutabakati;
use App\Services\Ekstre\PdfMetinOkuyucu;
use App\Support\Metin;
use Illuminate\Console\Command;

/**
 * Yalnızca yerel: gerçek bir ekstre PDF'inden anonim test verisi üretir (referans testi, tests/Fixtures/ekstre).
 *
 * - `sayfalar.json`: Tests\Support\OrnekPdf ile aynı sütun düzeninde sentetik PDF kuracak konumlu satırlar.
 *   İzin listesi: işlem satırları (tarih / tutar / taksit / bonus sütunları aynen, işyeri adı "ISYERI nn"; tür için
 *   gereken sistem satırları ve anahtar kelimeler kalır) ve bilinen etiket satırları (kesim, son ödeme, özet…).
 *   Kişisel veri temizleyicisinin yakaladığı her parça (ad, adres, kart no, telefon…) atılır; metin ASCII'dir.
 * - `beklenen.json`: gerçek PDF'in ayrıştırma sonucu (satır satır), aynı eşlemeyle anonim açıklamalar.
 *
 * Gerçek PDF depoya girmez; komut hiçbir şey loglamaz, yalnızca çıktı klasörüne yazar.
 */
class EkstreFixtureUret extends Command
{
    protected $signature = 'ekstre:fixture {pdf : Gerçek ekstre PDF dosyası} {klasor : Çıktı klasörü} {--satir-araligi=9}';

    protected $description = 'Gerçek ekstreden anonim referans test verisi üretir (yalnızca yerel)';

    /** Aynen kalan sistem satırları (tür ve bonus tanıma bunlara dayanır) */
    private const SISTEM = '/^(ODEMENIZ ICIN TESEKKUR EDERIZ|BONUS BEDAVA ALISVERIS|BONUS [A-Z ]+ KAMPANYASI|OTOM\. FATURA ISLEM FAIZI|ONCEKI DONEMDEN DEVIR EDILEN TUTAR)$/';

    /** Anonim adın sonuna eklenen tür kelimeleri */
    private const ANAHTARLAR = ['FAIZI', 'FAIZ', 'KKDF', 'BSMV', 'UCRETI', 'AIDATI', 'IADE', 'IPTAL', 'NAKIT AVANS', 'HAVALE', 'VIRMAN', 'EFT'];

    /** İşlem dışında alınan etiket satırları */
    private const ETIKETLER = ['HESAP KESIM TARIHI', 'SON ODEME TARIHI', 'MIN. ODEME TUTARI', 'TOPLAM BORCUNUZ', 'EKSTRE NO', 'BIR SONRAKI HESAP KESIMINIZ', 'ONCEKI HESAP KESIM TARIHINIZ', 'ISLEM TARIHI', 'ONCEKI BAKIYE', 'DONEM BORCUNUZ', 'EKSTRE OZETI', 'BONUS PROGRAM ORTAKLARI', 'ONCEKI DONEMDEN DEVIR EDILEN TUTAR', 'HARCANABILIR BONUS'];

    private const KOD_SAYFASI = ['Ý' => 'İ', 'ý' => 'ı', 'þ' => 'ş', 'Þ' => 'Ş', 'ð' => 'ğ', 'Ð' => 'Ğ'];

    private const ASCII = ['İ' => 'I', 'ı' => 'i', 'Ş' => 'S', 'ş' => 's', 'Ğ' => 'G', 'ğ' => 'g', 'Ü' => 'U', 'ü' => 'u', 'Ö' => 'O', 'ö' => 'o', 'Ç' => 'C', 'ç' => 'c', 'Â' => 'A', 'â' => 'a'];

    /** @var array<string, string> sade gerçek açıklama => anonim ad */
    private array $esleme = [];

    public function handle(PdfMetinOkuyucu $okuyucu, EkstreAyristirici $ayristirici, EkstreMutabakati $mutabakat, KisiselVeriTemizleyici $temizleyici): int
    {
        if (! app()->environment('local')) {
            $this->error('Bu komut yalnızca yerelde çalışır.');

            return self::FAILURE;
        }
        $icerik = @file_get_contents((string) $this->argument('pdf'));
        if ($icerik === false) {
            $this->error('PDF okunamadı.');

            return self::FAILURE;
        }

        $sayfalar = [];
        $tabloda = false;
        foreach ($okuyucu->konumluSatirlar($icerik) as $satirlar) {
            $cikti = [];
            foreach ($satirlar as $satir) {
                if (is_string($satir)) {
                    continue; // konumsuz düz metin yedeği alınmaz
                }
                $parcalar = array_map(fn (array $p): array => [(int) round($p[0]), strtr(trim($p[1]), self::KOD_SAYFASI)], $satir);
                $sade = Metin::sade(implode(' ', array_column($parcalar, 1)));
                if (str_contains($sade, 'ISLEM TARIHI') && str_contains($sade, 'DONEM ICI ISLEMLER')) {
                    $tabloda = true;
                } elseif (str_starts_with($sade, 'TOPLAM ') || str_starts_with($sade, 'EKSTRE OZETI')) {
                    $tabloda = false;
                }
                $yeni = $this->satir($parcalar, $sade, $tabloda, $temizleyici);
                if ($yeni !== null) {
                    $cikti[] = $yeni;
                }
            }
            $sayfalar[] = $cikti !== [] ? $cikti : [[40 => 'Bilgilendirme sayfasi']];
        }

        $okunan = $okuyucu->oku($icerik);
        $sonuc = $ayristirici->ayristir($okunan['sayfalar']);
        $satirlar = [];
        foreach ($sonuc['satirlar'] as $no => $s) {
            $satirlar[] = [
                'sira' => $no + 1, 'sayfa' => $s['sayfa'], 'islem_tarihi' => $s['islem_tarihi'], 'aciklama' => $this->anonimAd($s['aciklama']),
                'tutar' => $s['tutar'], 'tur' => $s['tur'], 'alacak' => $s['alacak'],
                'taksit_no' => $s['taksit_no'], 'taksit_sayisi' => $s['taksit_sayisi'], 'taksit_toplam' => $s['taksit_toplam'],
            ];
        }
        $denetim = $mutabakat->denetle($sonuc['alanlar'], $sonuc['ozet'], $sonuc['satirlar']);
        $beklenen = [
            'aciklama' => 'Gerçek bir kredi kartı ekstresinden anonim referans: işyeri adları yer tutucu, kişisel satırlar çıkarıldı, metin ASCII; tarih, tutar, taksit ve sayfa gerçek.',
            'sayfa_sayisi' => $okunan['sayfa_sayisi'],
            'profil' => $sonuc['profil'],
            'alanlar' => $sonuc['alanlar'],
            'ozet' => $sonuc['ozet'],
            'mutabakat' => ['durum' => $denetim['durum'], 'toplamlar' => $denetim['toplamlar']],
            'satirlar' => $satirlar,
            'bonus_hareketleri' => array_map(fn (array $b): array => [
                'sayfa' => $b['sayfa'], 'islem_tarihi' => $b['islem_tarihi'], 'aciklama' => $this->anonimAd($b['aciklama']), 'tutar' => $b['tutar'], 'yon' => $b['yon'],
            ], $sonuc['bonus_hareketleri']),
        ];

        $klasor = rtrim((string) $this->argument('klasor'), '/\\');
        @mkdir($klasor, 0775, true);
        $json = fn (mixed $v): string => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents("{$klasor}/sayfalar.json", $json(['satir_araligi' => (int) $this->option('satir-araligi'), 'sayfalar' => $sayfalar]));
        file_put_contents("{$klasor}/beklenen.json", $json($beklenen));
        $this->info(count($satirlar).' işlem satırı, '.count($sonuc['bonus_hareketleri']).' bonus hareketi, mutabakat: '.$denetim['durum'].'; '.count($this->esleme).' işyeri adı anonimleştirildi.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: int, 1: string}>  $parcalar
     * @return array<int, string>|null
     */
    private function satir(array $parcalar, string $sade, bool $tabloda, KisiselVeriTemizleyici $temizleyici): ?array
    {
        if (str_contains($sade, 'GARANTI BANKASI') || str_contains($sade, 'GARANTI BBVA')) {
            return [36 => 'T. Garanti Bankasi A.S.'];
        }
        // Kişisel veri içeren parça atılır (ad, adres, kart no, telefon, kimlik…)
        $parcalar = array_values(array_filter($parcalar, fn (array $p): bool => $temizleyici->temizle([$p[1]]) === [$p[1]]));
        if ($parcalar === []) {
            return null;
        }

        $tarihli = (bool) preg_match('/^\d{1,2} \p{L}+ \d{4}$/u', $parcalar[0][1]);
        if ($tabloda && $tarihli && count($parcalar) >= 2) {
            // İşlem satırı: açıklama sütunu (tarihten sonraki ilk parça) anonim, diğer sütunlar aynen
            $parcalar[1][1] = $this->anonimAd($parcalar[1][1]);

            return $this->ascii($parcalar);
        }
        $etiketli = array_filter(self::ETIKETLER, fn (string $e): bool => str_contains($sade, $e)) !== []
            || preg_match('/TL\s*[+=-]/', $sade)
            || str_starts_with($sade, 'TOPLAM');
        // Tablodaki kategori başlıkları (rakamsız, kısa) da alınır
        $baslik = $tabloda && ! preg_match('/\d/', $sade) && mb_strlen($sade) <= 40;
        // Limit gibi kişisel finans bilgisi referansa gerekmez
        if ((! $etiketli && ! $baslik) || str_contains($sade, 'LIMIT')) {
            return null;
        }
        foreach ($parcalar as &$p) {
            // Ekstre numarası: kesim tarihi kısmı kalır, kalan rakamlar sıfırlanır
            $p[1] = (string) preg_replace_callback('/(\d{2,4}-\d{8})(\d+)/', fn (array $e): string => $e[1].str_repeat('0', strlen($e[2])), $p[1]);
        }
        unset($p);

        return $this->ascii($parcalar);
    }

    private function anonimAd(string $aciklama): string
    {
        $sade = Metin::sade(strtr($aciklama, self::KOD_SAYFASI));
        if (preg_match(self::SISTEM, $sade)) {
            return strtr(strtr($aciklama, self::KOD_SAYFASI), self::ASCII);
        }
        if (! isset($this->esleme[$sade])) {
            $anahtar = array_values(array_filter(self::ANAHTARLAR, fn (string $a): bool => Metin::geciyor($sade, $a)))[0] ?? null;
            $this->esleme[$sade] = sprintf('ISYERI %02d', count($this->esleme) + 1).($anahtar !== null ? " {$anahtar}" : '');
        }

        return $this->esleme[$sade];
    }

    /**
     * @param  list<array{0: int, 1: string}>  $parcalar
     * @return array<int, string>
     */
    private function ascii(array $parcalar): array
    {
        $satir = [];
        foreach ($parcalar as [$x, $metin]) {
            $metin = strtr($metin, self::ASCII);
            // OrnekPdf yalnızca ASCII yazar; kalan ASCII dışı karakter "?" olur
            $satir[$x] = (string) preg_replace('/[^\x20-\x7E]/', '?', $metin);
        }

        return $satir;
    }
}
