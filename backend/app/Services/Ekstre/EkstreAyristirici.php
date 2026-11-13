<?php

namespace App\Services\Ekstre;

use App\Enums\KartIslemTuru;
use App\Support\Kurus;
use App\Support\Metin;
use Illuminate\Support\Carbon;

/**
 * Genel ekstre ayrıştırıcı (bankaya özel değil): metin satırlarından dönem bilgileri ve işlem satırları.
 *
 * İşlem satırı: başta tarih (gg.aa.yyyy, gg/aa/yyyy, gg.aa.yy; yıl yoksa kesim tarihinden), sonra açıklama, sonda
 * TL tutar(lar) (1.234,56; eksi, sondaki "-", "+", CR, ALACAK alacak sayılır). Birden çok tutar varsa ilki alınır ve
 * uyarılır (ör. puan sütunu), adaylar saklanır. Okunamayan alan uydurulmaz: `okuma.alanlar`'a yazılır; dönem
 * bilgisi bulunamazsa `okunamayan`'a. Tür bir tahmindir (kullanıcı incelemede değiştirir).
 */
class EkstreAyristirici
{
    private const TARIH = '(\d{1,2})[.\/-](\d{1,2})(?:[.\/-](\d{4}|\d{2}))?';

    private const TUTAR = '-?\d{1,3}(?:\.\d{3})+,\d{2}|-?\d+,\d{2}';

    /** Dönem bilgisi etiketleri (sade metin), öncelik sırasıyla */
    private const ETIKETLER = [
        'kesim_tarihi' => ['HESAP OZETI KESIM TARIHI', 'HESAP KESIM TARIHI', 'EKSTRE KESIM TARIHI', 'KESIM TARIHI', 'EKSTRE TARIHI', 'HESAP OZETI TARIHI'],
        'son_odeme_tarihi' => ['SON ODEME TARIHI'],
        'donem_baslangic' => ['DONEM BASLANGIC TARIHI', 'ONCEKI HESAP KESIM TARIHI', 'ONCEKI KESIM TARIHI', 'ONCEKI EKSTRE TARIHI'],
        'donem_borcu' => ['GUNCEL DONEM BORCU', 'HESAP OZETI BORCU', 'DONEM BORCU', 'EKSTRE BORCU', 'TOPLAM BORC', 'ODENECEK TOPLAM TUTAR'],
        'asgari_odeme' => ['ASGARI ODEME TUTARI', 'ASGARI ODEME'],
    ];

    /** İşlem sayılmayan satırlar (özet / başlık) */
    private const OZET_KELIMELERI = ['SON ODEME', 'ASGARI', 'DONEM BORCU', 'KESIM TARIHI', 'HESAP OZETI', 'ONCEKI DONEM', 'TOPLAM BORC', 'KART LIMITI', 'KULLANILABILIR'];

    /** Dönem bilgisinde zorunlu sayılanlar (bulunamazsa "okunamadı") */
    public const ZORUNLU_ALANLAR = ['kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'];

    /**
     * @param  list<list<string>>  $sayfalar
     * @return array{alanlar: array<string, string|null>, okunamayan: list<string>, satirlar: list<array<string, mixed>>}
     */
    public function ayristir(array $sayfalar): array
    {
        $alanlar = $this->alanlar($sayfalar);
        $kesim = $alanlar['kesim_tarihi'] !== null ? Carbon::parse($alanlar['kesim_tarihi']) : null;

        $satirlar = [];
        foreach ($sayfalar as $i => $sayfa) {
            foreach ($sayfa as $satir) {
                $islem = $this->islemSatiri($satir, $kesim);
                if ($islem !== null) {
                    $satirlar[] = ['sayfa' => $i + 1, ...$islem];
                }
            }
        }

        return [
            'alanlar' => $alanlar,
            'okunamayan' => array_values(array_filter(self::ZORUNLU_ALANLAR, fn (string $a): bool => $alanlar[$a] === null)),
            'satirlar' => $satirlar,
        ];
    }

    /**
     * Dönem bilgileri: etiketin geçtiği satırda etiketten sonraki ilk tarih / tutar; satırda yoksa bir sonraki satırda
     * (etiketler bir satırda, değerler altında olan tablolarda sırayla eşlenir).
     *
     * @param  list<list<string>>  $sayfalar
     * @return array<string, string|null>
     */
    private function alanlar(array $sayfalar): array
    {
        $sonuc = array_fill_keys(array_keys(self::ETIKETLER), null);
        $satirlar = array_map(fn (string $s): string => Metin::sade($s), array_merge(...($sayfalar ?: [[]])));

        foreach ($satirlar as $no => $satir) {
            // Bu satırdaki etiketler (konumuyla). "ÖNCEKİ DÖNEM BORCU", "ÖNCEKİ HESAP KESİM TARİHİ" bu dönemin değil
            // (yalnızca dönem başlangıcı ÖNCEKİ etiketini arar)
            $bulunan = [];
            foreach (self::ETIKETLER as $alan => $etiketler) {
                if ($sonuc[$alan] !== null) {
                    continue;
                }
                foreach ($etiketler as $etiket) {
                    $konum = $this->etiketKonumu($satir, $etiket, $alan !== 'donem_baslangic');
                    if ($konum !== null && ! isset($bulunan[$konum])) {
                        $bulunan[$konum] = [$alan, $konum + strlen($etiket)];
                        break;
                    }
                }
            }
            if ($bulunan === []) {
                continue;
            }
            ksort($bulunan);
            $konumlar = array_keys($bulunan);
            $alanSirasi = array_values($bulunan);
            foreach ($alanSirasi as $k => [$alan, $son]) {
                // Değer bu etiketten sonraki etikete kadar aranır (yan yana etiketlerde karışmasın)
                $bitis = $konumlar[$k + 1] ?? strlen($satir);
                $sonuc[$alan] = $this->deger($alan, substr($satir, $son, max(0, $bitis - $son)));
            }
            // Değerler bir alt satırdaysa sırayla eşle
            $eksik = array_values(array_filter($alanSirasi, fn (array $a): bool => $sonuc[$a[0]] === null));
            if ($eksik !== [] && isset($satirlar[$no + 1])) {
                $tarihler = $this->tumTarihler($satirlar[$no + 1]);
                $tutarlar = $this->tumTutarlar($satirlar[$no + 1]);
                foreach ($eksik as [$alan]) {
                    $sonuc[$alan] = str_ends_with($alan, 'tarihi') || $alan === 'donem_baslangic'
                        ? array_shift($tarihler)
                        : (($t = array_shift($tutarlar)) !== null ? $t : null);
                }
            }
        }

        return $sonuc;
    }

    /** Etiketin ilk geçtiği yer; `$oncekisiz` ise hemen önünde "ÖNCEKİ" olanlar atlanır */
    private function etiketKonumu(string $satir, string $etiket, bool $oncekisiz): ?int
    {
        $bas = 0;
        while (($konum = strpos($satir, $etiket, $bas)) !== false) {
            if (! $oncekisiz || ! str_contains(substr($satir, max(0, $konum - 20), min(20, $konum)), 'ONCEKI')) {
                return $konum;
            }
            $bas = $konum + 1;
        }

        return null;
    }

    private function deger(string $alan, string $metin): ?string
    {
        if (str_ends_with($alan, 'tarihi') || $alan === 'donem_baslangic') {
            return $this->tumTarihler($metin)[0] ?? null;
        }

        return $this->tumTutarlar($metin)[0] ?? null;
    }

    /**
     * @return list<string> Y-m-d
     */
    private function tumTarihler(string $metin): array
    {
        preg_match_all('/(?<!\d)(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})(?!\d)/', $metin, $e, PREG_SET_ORDER);

        return array_values(array_filter(array_map(fn (array $m): ?string => $this->tarih((int) $m[1], (int) $m[2], (int) $m[3]), $e)));
    }

    /**
     * @return list<string> decimal metin (mutlak)
     */
    private function tumTutarlar(string $metin): array
    {
        preg_match_all('/(?<![\d.,])('.self::TUTAR.')(?![\d,])/', $metin, $e);

        return array_map(fn (string $t): string => Kurus::decimal(abs(Kurus::kurus($this->sayi($t)))), $e[1]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function islemSatiri(string $ham, ?Carbon $kesim): ?array
    {
        $satir = trim(str_replace("\t", '  ', $ham));
        if (! preg_match('/^'.self::TARIH.'\s+(.+)$/u', $satir, $e)) {
            return null;
        }
        $kalan = $e[4];
        // İkinci tarih (valör / kayıt tarihi) açıklama değildir
        $kalan = (string) preg_replace('/^'.self::TARIH.'\s+/', '', $kalan);

        // Sondaki tutarlar (sağdan sola); her birinin alacak işareti ayrı
        $adaylar = [];
        while (preg_match('/(?:^|\s)('.self::TUTAR.')\s*(?:TL|TRY)?\s*(-|\+|CR|ALACAK)?\s*$/u', $kalan, $t, PREG_OFFSET_CAPTURE)) {
            $isaret = isset($t[2]) && $t[2][1] >= 0 && $t[2][0] !== '';
            array_unshift($adaylar, [$t[1][0], str_starts_with($t[1][0], '-') || $isaret]);
            $kalan = rtrim(substr($kalan, 0, $t[0][1]));
        }
        $tutarlar = array_column($adaylar, 0);
        // Asıl tutar soldaki (sonrakiler puan / döviz sütunu olabilir)
        $alacak = $adaylar[0][1] ?? false;
        $aciklama = trim((string) preg_replace('/\s{2,}/', ' ', $kalan));
        if ($tutarlar === [] || $aciklama === '') {
            return null;
        }
        $sade = Metin::sade($aciklama);
        foreach (self::OZET_KELIMELERI as $kelime) {
            if (str_contains($sade, $kelime)) {
                return null;
            }
        }
        // Yalnızca tarih aralığı / sayfa no olan satırlar
        if (! preg_match('/[A-ZÇĞİÖŞÜ]{2,}/u', $sade)) {
            return null;
        }

        $okunamayan = [];
        $uyarilar = [];
        $tarih = null;
        $gun = (int) $e[1];
        $ay = (int) $e[2];
        if (($e[3] ?? '') !== '') {
            $yil = (int) $e[3];
            $tarih = $this->tarih($gun, $ay, $yil < 100 ? 2000 + $yil : $yil);
        } elseif ($kesim) {
            // Yıl yazmıyor: kesimden sonraki ay geçen yıldır (Aralık harcaması Ocak ekstresinde)
            $tarih = $this->tarih($gun, $ay, $ay > $kesim->month ? $kesim->year - 1 : $kesim->year);
            $uyarilar[] = 'Yıl ekstre döneminden alındı.';
        }
        if ($tarih === null) {
            $okunamayan[] = 'islem_tarihi';
        }

        $tutar = Kurus::decimal(abs(Kurus::kurus($this->sayi($tutarlar[0]))));
        if (count($tutarlar) > 1) {
            $uyarilar[] = 'Satırda birden çok tutar var ('.implode(' · ', $tutarlar).'); ilki alındı, doğru olanı seçin.';
        }
        [$taksitNo, $taksitSayisi] = $this->taksit($sade);

        return [
            'ham' => trim($ham),
            'islem_tarihi' => $tarih,
            'aciklama' => mb_substr($aciklama, 0, 255),
            'tutar' => Kurus::kurus($tutar) > 0 ? $tutar : null,
            'alacak' => $alacak,
            'tur' => $this->tur($sade, $alacak)->value,
            'taksit_no' => $taksitNo,
            'taksit_sayisi' => $taksitSayisi,
            'taksit_toplam' => preg_match('/TOPLAM\s*:?\s*('.self::TUTAR.')/', $sade, $tt) ? Kurus::decimal(abs(Kurus::kurus($this->sayi($tt[1])))) : null,
            'okuma' => [
                'alanlar' => Kurus::kurus($tutar) > 0 ? $okunamayan : [...$okunamayan, 'tutar'],
                'uyarilar' => $uyarilar,
                'tutar_adaylari' => count($tutarlar) > 1 ? array_map(fn (string $t): string => Kurus::decimal(abs(Kurus::kurus($this->sayi($t)))), $tutarlar) : [],
            ],
        ];
    }

    /**
     * Taksit: "3/6", "(3/6)", "TAKSIT 3/6", "3/6 TAKSIT", "3. TAKSIT / 6"; geçersizse (3/2) yok sayılır.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public function taksit(string $sade): array
    {
        $desenler = [
            '/TAKSIT\w*\s*:?\s*(\d{1,2})\s*\/\s*(\d{1,2})/',
            '/(\d{1,2})\s*\/\s*(\d{1,2})\s*TAKS/',
            '/(\d{1,2})\s*\.\s*TAKSIT\s*\/?\s*(\d{1,2})/',
            '/\(\s*(\d{1,2})\s*\/\s*(\d{1,2})\s*\)/',
            '/(?:^|\s)(\d{1,2})\s*\/\s*(\d{1,2})$/',
        ];
        foreach ($desenler as $desen) {
            if (preg_match($desen, $sade, $e) && (int) $e[1] >= 1 && (int) $e[2] >= 2 && (int) $e[2] <= 36 && (int) $e[1] <= (int) $e[2]) {
                return [(int) $e[1], (int) $e[2]];
            }
        }

        return [null, null];
    }

    /** Tür tahmini (sade açıklama + alacak mı) */
    public function tur(string $sade, bool $alacak): KartIslemTuru
    {
        $var = function (array $kelimeler) use ($sade): bool {
            foreach ($kelimeler as $kelime) {
                if (Metin::geciyor($sade, $kelime)) {
                    return true;
                }
            }

            return false;
        };

        if ($var(['NAKIT AVANS', 'ATM NAKIT'])) {
            return KartIslemTuru::NakitAvans;
        }
        if ($var(['VIRMAN', 'HAVALE', 'EFT'])) {
            return KartIslemTuru::Transfer;
        }
        if ($alacak) {
            // Alacak: kart borcu ödemesi ya da iade. "ISKI OTOMATIK ODEME" gibi borç satırı faturadır, burada değil.
            return $var(['ODEME', 'ODEMENIZ', 'HESAPTAN ODEME', 'BORC ODEME', 'KART ODEME', 'TAHSILAT', 'ODEMENIZ ICIN TESEKKURLER'])
                ? KartIslemTuru::Odeme
                : KartIslemTuru::Iade;
        }
        if ($var(['IADE', 'IPTAL'])) {
            return KartIslemTuru::Iade;
        }
        if ($var(['FAIZ', 'KKDF', 'BSMV', 'UCRET', 'KART AIDATI', 'YILLIK UYELIK', 'GECIKME'])) {
            return KartIslemTuru::Ucret;
        }

        return KartIslemTuru::Harcama;
    }

    /** "1.234,56" / "-12,50" → "1234.56" / "-12.50" */
    private function sayi(string $tutar): string
    {
        return str_replace(['.', ',', ' '], ['', '.', ''], trim($tutar));
    }

    private function tarih(int $gun, int $ay, int $yil): ?string
    {
        return checkdate($ay, $gun, $yil) && $yil >= 2000 && $yil <= 2100 ? sprintf('%04d-%02d-%02d', $yil, $ay, $gun) : null;
    }
}
