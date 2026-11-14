<?php

namespace App\Services\Ekstre;

use App\Enums\KartIslemTuru;
use App\Services\Ekstre\Profil\EkstreProfili;
use App\Support\Kurus;
use App\Support\Metin;
use Illuminate\Support\Carbon;

/**
 * Ekstre ayrıştırıcı: metin satırlarından dönem bilgileri ve işlem satırları. Ortak boru hattı tek yerde; bankaya
 * özgü yazım `Profil\*` sınıflarındaki bildirimsel kurallardan gelir (banka `BankaTanici` ile tanınır).
 *
 * Boru hattı: normalize (kod sayfası, ay adları) → banka → başlık alanları (etiket + aynı / alt satırdaki değer,
 * sonra profilin türettikleri) → tablo satırları (profil tablo sınırı biliyorsa yalnızca içindekiler) → devam satırları
 * önceki işleme → tür / taksit / alacak → kuruş.
 *
 * İşlem satırı: başta tarih (gg.aa.yyyy, gg/aa/yyyy, gg.aa.yy; yıl yoksa kesim tarihinden), sonra açıklama, sonda
 * TL tutar(lar). Eksi, önde `+` (Yapı Kredi), boşluklu `- 2.081,00` (Enpara), sondaki `-` / CR / ALACAK alacak sayılır.
 * Birden çok tutar varsa profilin dediği (soldaki / sağdaki) alınır ve uyarılır; adaylar saklanır. Okunamayan alan
 * uydurulmaz: `okuma.alanlar`'a yazılır; dönem bilgisi bulunamazsa `okunamayan`'a. Tür bir tahmindir.
 */
class EkstreAyristirici
{
    private const TARIH = '(\d{1,2})[.\/-](\d{1,2})(?:[.\/-](\d{4}|\d{2}))?';

    private const TUTAR = '\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2}';

    /** Dönem bilgisi etiketleri (sade metin), öncelik sırasıyla; profilinkiler öne eklenir */
    private const ETIKETLER = [
        'kesim_tarihi' => ['HESAP OZETI KESIM TARIHI', 'HESAP KESIM TARIHI', 'EKSTRE KESIM TARIHI', 'KESIM TARIHI', 'EKSTRE TARIHI', 'HESAP OZETI TARIHI'],
        'son_odeme_tarihi' => ['SON ODEME TARIHI'],
        'donem_baslangic' => ['DONEM BASLANGIC TARIHI', 'ONCEKI HESAP KESIM TARIHI', 'ONCEKI KESIM TARIHI', 'ONCEKI EKSTRE TARIHI'],
        'donem_borcu' => ['GUNCEL DONEM BORCU', 'HESAP OZETI BORCU', 'DONEM BORCU', 'EKSTRE BORCU', 'TOPLAM BORC', 'ODENECEK TOPLAM TUTAR'],
        'asgari_odeme' => ['ASGARI ODEME TUTARI', 'ASGARI ODEME', 'MINIMUM ODEME TUTARI', 'MIN. ODEME TUTARI'],
        'onceki_borc' => ['ONCEKI DONEM HESAP OZETI BORCU', 'ONCEKI DONEM BORCU', 'ONCEKI EKSTRE BORCU', 'ONCEKI EKSTRE BAKIYE', 'DEVREDEN BAKIYE'],
    ];

    /** "ÖNCEKİ …" ile başlayan yazımın bu dönemin değeri olmadığı alanlar */
    private const ONCEKISIZ = ['kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'];

    /** İşlem sayılmayan satırlar (özet / başlık) */
    private const OZET_KELIMELERI = ['SON ODEME', 'ASGARI', 'DONEM BORCU', 'KESIM TARIHI', 'HESAP OZETI', 'ONCEKI DONEM', 'TOPLAM BORC', 'KART LIMITI', 'KULLANILABILIR', 'EKSTRE BAKIYE'];

    /** Dönem bilgisinde zorunlu sayılanlar (bulunamazsa "okunamadı") */
    public const ZORUNLU_ALANLAR = ['kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'];

    /** Ekstre kaydına yazılan dönem alanları (ötekiler `ozet`te) */
    private const EKSTRE_ALANLARI = ['kesim_tarihi', 'son_odeme_tarihi', 'donem_baslangic', 'donem_borcu', 'asgari_odeme'];

    public function __construct(private MetinNormalizer $normalizer, private BankaTanici $tanici) {}

    /**
     * @param  list<list<string>>  $sayfalar
     * @return array{profil: string, profil_etiketi: string, alanlar: array<string, string|null>, ozet: array<string, string|null>, okunamayan: list<string>, satirlar: list<array<string, mixed>>}
     */
    public function ayristir(array $sayfalar, ?EkstreProfili $profil = null): array
    {
        $sayfalar = $this->normalizer->normalize($sayfalar);
        $profil ??= $this->tanici->tani($sayfalar);
        $sadeSatirlar = array_map(fn (string $s): string => Metin::sade($s), array_merge(...($sayfalar ?: [[]])));

        $alanlar = $this->alanlar($sadeSatirlar, $profil);
        foreach ($profil->turetilenAlanlar($alanlar, $sadeSatirlar) as $alan => $deger) {
            if ($deger !== null) {
                $alanlar[$alan] = $deger;
            }
        }
        $kesim = ($alanlar['kesim_tarihi'] ?? null) !== null ? Carbon::parse($alanlar['kesim_tarihi']) : null;

        $satirlar = [];
        // Profil tablo başlığı bekler; belgede hiç yoksa (biçim değişti) satırları atlamak yerine hepsi aday olur
        $sinirli = $profil->tabloSinirliMi() && array_filter($sadeSatirlar, fn (string $s): bool => $profil->tabloBasi($s)) !== [];
        $tabloIcinde = ! $sinirli;
        foreach ($sayfalar as $i => $sayfa) {
            foreach ($sayfa as $ham) {
                $sade = Metin::sade($ham);
                if ($sinirli) {
                    if ($profil->tabloBasi($sade)) {
                        $tabloIcinde = true;

                        continue;
                    }
                    if ($tabloIcinde && $profil->tabloSonu($sade)) {
                        $toplam = $profil->toplamSatiri($sade);
                        if ($toplam !== null) {
                            $alanlar['toplam_satiri'] = $toplam;
                        }
                        $tabloIcinde = false;

                        continue;
                    }
                    if (! $tabloIcinde || $profil->atla($sade)) {
                        continue;
                    }
                }
                if ($satirlar !== [] && ($ek = $profil->devamSatiri($sade)) !== null) {
                    $son = array_key_last($satirlar);
                    $satirlar[$son] = [...$satirlar[$son], ...$ek];

                    continue;
                }
                $islem = $this->islemSatiri($ham, $kesim, $profil);
                if ($islem !== null) {
                    $satirlar[] = ['sayfa' => $i + 1, ...$islem];
                }
            }
        }

        $ekstreAlanlari = array_intersect_key($alanlar, array_flip(self::EKSTRE_ALANLARI));

        return [
            'profil' => $profil->ad(),
            'profil_etiketi' => $profil->etiket(),
            'alanlar' => $ekstreAlanlari + array_fill_keys(self::EKSTRE_ALANLARI, null),
            'ozet' => array_diff_key($alanlar, array_flip(self::EKSTRE_ALANLARI)),
            'okunamayan' => array_values(array_filter(self::ZORUNLU_ALANLAR, fn (string $a): bool => ($alanlar[$a] ?? null) === null)),
            'satirlar' => $satirlar,
        ];
    }

    /**
     * Dönem bilgileri: etiketin geçtiği satırda etiketten sonraki ilk tarih / tutar; satırda yoksa bir sonraki satırda
     * (etiketler bir satırda, değerler altında olan tablolarda sırayla eşlenir).
     *
     * @param  list<string>  $satirlar  sade
     * @return array<string, string|null>
     */
    private function alanlar(array $satirlar, EkstreProfili $profil): array
    {
        $etiketler = self::ETIKETLER;
        foreach ($profil->alanEtiketleri() as $alan => $liste) {
            $etiketler[$alan] = [...$liste, ...($etiketler[$alan] ?? [])];
        }
        $sonuc = array_fill_keys(array_keys($etiketler), null);

        foreach ($satirlar as $no => $satir) {
            // Bu satırdaki etiketler (konumuyla). "ÖNCEKİ DÖNEM BORCU", "ÖNCEKİ HESAP KESİM TARİHİ" bu dönemin değil
            $bulunan = [];
            foreach ($etiketler as $alan => $liste) {
                if ($sonuc[$alan] !== null) {
                    continue;
                }
                foreach ($liste as $etiket) {
                    $konum = $this->etiketKonumu($satir, $etiket, in_array($alan, self::ONCEKISIZ, true));
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
                    $sonuc[$alan] = self::tarihAlani($alan) ? array_shift($tarihler) : array_shift($tutarlar);
                }
            }
        }

        return $sonuc;
    }

    private static function tarihAlani(string $alan): bool
    {
        return str_ends_with($alan, 'tarihi') || $alan === 'donem_baslangic';
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
        return self::tarihAlani($alan) ? ($this->tumTarihler($metin)[0] ?? null) : ($this->tumTutarlar($metin)[0] ?? null);
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

        return array_map(fn (string $t): string => self::mutlak($t), $e[1]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function islemSatiri(string $ham, ?Carbon $kesim, EkstreProfili $profil): ?array
    {
        $ham = rtrim($ham);
        $ek = [];
        // Sağdaki tutar olmayan sütunlar (puan, kalan taksit)
        $degisti = true;
        while ($degisti) {
            $degisti = false;
            foreach ($profil->sondakiSutunlar() as $desen) {
                if (preg_match($desen, $ham, $e)) {
                    if (isset($e['kalan'])) {
                        $ek['taksit_kalan'] = self::mutlak($e['kalan']);
                    }
                    $ham = rtrim(substr($ham, 0, -strlen($e[0])));
                    $degisti = true;
                }
            }
        }

        $satir = trim(str_replace("\t", '  ', $ham));
        if (! preg_match('/^'.self::TARIH.'\s+(.+)$/u', $satir, $e)) {
            return null;
        }
        $kalan = $e[4];
        // İkinci tarih (valör / kayıt tarihi) açıklama değildir
        $kalan = (string) preg_replace('/^'.self::TARIH.'\s+/', '', $kalan);

        // Sondaki tutarlar (sağdan sola); her birinin alacak işareti ayrı (önde -, önde +, sonda -, +, CR, ALACAK)
        $adaylar = [];
        while (preg_match('/(?:^|\s)([-+])?\s?('.self::TUTAR.')\s*(?:TL|TRY)?\s*(-|\+|CR|ALACAK)?\s*$/u', $kalan, $t, PREG_OFFSET_CAPTURE)) {
            $alacakMi = ($t[1][0] ?? '') !== '' || (isset($t[3]) && $t[3][1] >= 0 && $t[3][0] !== '');
            array_unshift($adaylar, [$t[2][0], $alacakMi]);
            $kalan = rtrim(substr($kalan, 0, $t[0][1]));
        }
        if ($adaylar === []) {
            return null;
        }
        $secilen = $profil->tutarKonumu() === 'son' ? array_key_last($adaylar) : 0;
        $tutarlar = array_column($adaylar, 0);
        $alacak = $adaylar[$secilen][1];
        $aciklama = trim((string) preg_replace('/\s{2,}/', ' ', $kalan));
        $aciklama = $profil->aciklamaTemizle($aciklama);
        if ($aciklama === '') {
            return null;
        }
        $sadeTam = Metin::sade($e[4]);
        $sade = Metin::sade($aciklama);
        foreach (self::OZET_KELIMELERI as $kelime) {
            if (str_contains($sade, $kelime)) {
                return null;
            }
        }
        // Yalnızca tarih aralığı / sayfa no olan satırlar
        // (en az iki harf: "G.E. 0000…" gibi kısa açıklamalar da işlemdir)
        if (preg_match_all('/[A-ZÇĞİÖŞÜ]/u', $sade) < 2) {
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

        $tutar = self::mutlak($tutarlar[$secilen]);
        if (count($tutarlar) > 1) {
            $uyarilar[] = 'Satırda birden çok tutar var ('.implode(' · ', $tutarlar).'); '.($secilen === 0 ? 'soldaki' : 'sağdaki').' alındı, doğru olanı seçin.';
        }
        [$taksitNo, $taksitSayisi, $taksitToplam] = $profil->taksit($sadeTam) ?? [...$this->taksit($sadeTam), null];
        $taksitToplam ??= preg_match('/TOPLAM\s*:?\s*('.self::TUTAR.')/', $sade, $tt) ? self::mutlak($tt[1]) : null;

        return [
            'ham' => trim($ham),
            'islem_tarihi' => $tarih,
            'aciklama' => mb_substr($aciklama, 0, 255),
            'tutar' => Kurus::kurus($tutar) > 0 ? $tutar : null,
            'alacak' => $alacak,
            'tur' => $this->tur($sade, $alacak)->value,
            'taksit_no' => $taksitNo,
            'taksit_sayisi' => $taksitSayisi,
            'taksit_toplam' => $taksitToplam,
            'taksit_kalan' => $ek['taksit_kalan'] ?? null,
            'okuma' => [
                'alanlar' => Kurus::kurus($tutar) > 0 ? $okunamayan : [...$okunamayan, 'tutar'],
                'uyarilar' => $uyarilar,
                'tutar_adaylari' => count($tutarlar) > 1 ? array_map(fn (string $t): string => self::mutlak($t), $tutarlar) : [],
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
            // Alacak: kart borcu ödemesi, bonus / puanla ödeme ya da iade. "ISKI OTOMATIK ODEME" gibi borç satırı
            // faturadır, burada değil.
            if ($var(['ODEME', 'ODEMENIZ', 'HESAPTAN ODEME', 'BORC ODEME', 'KART ODEME', 'TAHSILAT', 'ODEMENIZ ICIN TESEKKURLER'])) {
                return KartIslemTuru::Odeme;
            }

            return $var(['BONUS', 'BEDAVA', 'PUAN', 'WORLDPUAN', 'MAXIPUAN', 'CHIP PARA', 'PARAPUAN'])
                ? KartIslemTuru::Bonus
                : KartIslemTuru::Iade;
        }
        if ($var(['IADE', 'IPTAL'])) {
            return KartIslemTuru::Iade;
        }
        // Vergi, faiz ("GECİKME FAİZİ" faizdir), ücret / aidat; "FAİZİ", "ÜCRETİ", "AİDATI" gibi ekli yazımlar da
        if ($var(['KKDF', 'BSMV'])) {
            return KartIslemTuru::Vergi;
        }
        if (preg_match('/(^|[^A-Z0-9])FAIZ(I|LERI|LERIN)?(?![A-Z0-9])/', $sade)) {
            return KartIslemTuru::Faiz;
        }
        if ($var(['KART AIDATI', 'YILLIK UYELIK']) || preg_match('/(^|[^A-Z0-9])(UCRET|AIDAT|GECIKME)(I|SI|LERI|LERIN)?(?![A-Z0-9])/', $sade)) {
            return KartIslemTuru::Ucret;
        }

        return KartIslemTuru::Harcama;
    }

    /** "1.234,56" / "-12,50" → mutlak decimal "1234.56" */
    private static function mutlak(string $tutar): string
    {
        return Kurus::decimal(abs(Kurus::kurus(str_replace(['.', ',', ' ', '+', '-'], ['', '.', '', '', ''], trim($tutar)))));
    }

    private function tarih(int $gun, int $ay, int $yil): ?string
    {
        return checkdate($ay, $gun, $yil) && $yil >= 2000 && $yil <= 2100 ? sprintf('%04d-%02d-%02d', $yil, $ay, $gun) : null;
    }
}
