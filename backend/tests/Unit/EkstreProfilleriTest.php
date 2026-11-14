<?php

namespace Tests\Unit;

use App\Services\Ekstre\BankaTanici;
use App\Services\Ekstre\EkstreAyristirici;
use App\Services\Ekstre\MetinNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Banka profilleri: gerçek ekstrelerin yazım kalıplarıyla (uydurma veriler) Garanti BBVA, Yapı Kredi, Enpara.
 * Gerçek PDF depoda yoktur; satırlar PdfMetinOkuyucu'nun ürettiği biçimdedir (sütunlar sekmeyle).
 */
class EkstreProfilleriTest extends TestCase
{
    private function ayristir(array ...$sayfalar): array
    {
        return (new EkstreAyristirici(new MetinNormalizer, new BankaTanici))->ayristir($sayfalar);
    }

    public function test_banka_tanima(): void
    {
        $t = new BankaTanici;
        $this->assertSame('garanti', $t->tani([['T. Garanti Bankası A.Ş. Genel Müdürlük']])->ad());
        $this->assertSame('yapikredi', $t->tani([['x'], ['TÜM ÜYE İŞYERLERİNDE GEÇERLİ WORLDPUAN BİLGİLERİ']])->ad());
        $this->assertSame('enpara', $t->tani([['Enpara Bank A.Ş.']])->ad());
        // Yalnızca ilk iki sayfaya bakılır; tanınmazsa genel
        $this->assertSame('genel', $t->tani([['a'], ['b'], ['Enpara Bank']])->ad());
        $this->assertSame('genel', $t->tani([['Bir banka']])->ad());
        $this->assertSame('garanti', $t->profil('garanti')->ad());
        $this->assertSame('genel', $t->profil('yok')->ad());
    }

    public function test_garanti(): void
    {
        $sonuc = $this->ayristir([
            'T. Garanti Bankasý A.Þ. Genel Müdürlük : Nispetiye Mah.',
            "Kart Numarasý 9792 29** **** 1029\tSon Ödeme Tarihi 20 Kasým 2026",
            "Harcanabilir Bonus (TL) 4,68\tToplam Borcunuz 10.000,00 TL",
            'Ekstre No : 910-20261111051240995438',
            "Ýþlem Tarihi Dönem Ýçi Ýþlemler\tKalan Borç / Taksit Bonus (TL)\tTutar (TL)",
            "21 Ekim 2026 ÖDEMENÝZ ÝÇÝN TEÞEKKÜR EDERÝZ \t-6.000,00",
            'Kitap Evi & Kýrtasiye',
            "22 Ekim 2026 SANLI LAHMACUN \t1.440,00",
            "12 Ekim 2026 BONUS BEDAVA ALIÞVERÝÞ \t-301,39",
            "03 Kasým 2026 IYZICO/SHOP.APIMAYE.COM.T 756,00x4=3.024,00 1.Taksit 2,72\t756,00",
            "26 Ekim 2026 OTOM. FATURA ÝÞLEM FAÝZÝ \t19,39",
            'DÝÐER HARCAMALARINIZ',
            "Toplam\t-1.296,71\t10.000,00",
            "EKSTRE ÖZETÝ Bir sonraki hesap kesiminiz 11 Aralýk 2026 Cuma ve son ödemeniz 21 Aralýk 2026 Pazartesi 'dir. Bir önceki hesap kesim tarihiniz 11 Ekim 2026",
            'Önceki BakiyeDönem Harcamalarý Faiz ve Ücretler Ödemeleriniz Dönem Borcunuz Min. Ödeme Tutarýnýz',
            '6.000,00 TL+ 9.980,61 TL+ 19,39 TL- 6.000,00 TL= 10.000,00 TL 4.000,00 TL',
            '15 Ekim 2026 itibarýyla Pound cinsinden 100,00 TL sözleþme metni',
        ]);

        $this->assertSame('garanti', $sonuc['profil']);
        $this->assertSame([
            'kesim_tarihi' => '2026-11-11',
            'son_odeme_tarihi' => '2026-11-20',
            'donem_baslangic' => '2026-10-11',
            'donem_borcu' => '10000.00',
            'asgari_odeme' => '4000.00',
        ], $sonuc['alanlar']);
        $this->assertSame(['6000.00', '9980.61', '19.39', '6000.00', '10000.00'], [
            $sonuc['ozet']['onceki_borc'], $sonuc['ozet']['harcama_toplami'], $sonuc['ozet']['faiz_toplami'], $sonuc['ozet']['odeme_toplami'], $sonuc['ozet']['toplam_satiri'],
        ]);
        // Tablo dışındaki tarihli satır (sözleşme) işlem değil; kategori başlığı işlem değil
        $this->assertCount(5, $sonuc['satirlar']);
        [$odeme, $lahmacun, $bonus, $taksit, $faiz] = $sonuc['satirlar'];
        $this->assertSame(['2026-10-21', 'odeme', '6000.00', true], [$odeme['islem_tarihi'], $odeme['tur'], $odeme['tutar'], $odeme['alacak']]);
        $this->assertSame(['SANLI LAHMACUN', '1440.00', 'harcama'], [$lahmacun['aciklama'], $lahmacun['tutar'], $lahmacun['tur']]);
        $this->assertSame(['bonus', true], [$bonus['tur'], $bonus['alacak']]);
        // Sağdaki tutar asıl (ortadaki 2,72 bonus); taksit A x N = T n.Taksit; açıklama temiz
        $this->assertSame(['756.00', 1, 4, '3024.00', 'IYZICO/SHOP.APIMAYE.COM.T'], [$taksit['tutar'], $taksit['taksit_no'], $taksit['taksit_sayisi'], $taksit['taksit_toplam'], $taksit['aciklama']]);
        $this->assertSame(['756.00', '2.72'], [$taksit['tutar'], $taksit['okuma']['tutar_adaylari'][0]]);
        $this->assertSame('faiz', $faiz['tur']);
    }

    public function test_yapi_kredi(): void
    {
        $sonuc = $this->ayristir([
            'TÜM ÜYE İŞYERLERİNDE GEÇERLİ WORLDPUAN BİLGİLERİ',
            "Hesap Kesim Tarihi\t: 13 Ekim 2026",
            "Son Ödeme Tarihi\t: 23 Ekim 2026",
            "Dönem Borcu\t: 7.736,49 TL",
            "Ödenmesi Gereken Asgari Tutar/Oran : 3.094,60 TL / 40%\tSn. AD SOYAD",
            'Bir Sonraki Ay Hesap Kesim Tarihi : 13 Kasım 2026',
            "Kart Limiti\t: 202.500,00 TL",
            "İşlem Tarihi İşlemler\tTutar(TL) Kalan Tutar/Taksit\tPuan",
            "ÖNCEKİ DÖNEM HESAP ÖZETİ BORCU\t8.085,68",
            "21 Eylül 2026 ÖDEME-İNTERNET BANKACILIĞI\t+8.085,68",
            "28 Eylül 2026 HEPSIPAY    *HEPSIBURADA ISTANBUL     TR\t211,66\t4",
            "28 Eylül 2026 HEPSIPAY    *HEPSIBURADA ISTANBUL     TR\t+115,42\t-2",
            "11 Ağustos 2026 HEPSIPAY    *HEPSIBURADA ISTANBUL     TR\t1.757,80\t10.546,80 / 6",
            "15.820,17 TL'lik işlemin 3 / 9 taksidi",
            "TOPLAM\t7.736,49\t12",
            'PUAN ÖZETİ',
            "WORLDPUAN\t1.414\t12\t0\t1.426\t7,13",
        ]);

        $this->assertSame('yapikredi', $sonuc['profil']);
        $this->assertSame(['2026-10-13', '2026-10-23', '7736.49', '3094.60', null], [
            $sonuc['alanlar']['kesim_tarihi'], $sonuc['alanlar']['son_odeme_tarihi'], $sonuc['alanlar']['donem_borcu'], $sonuc['alanlar']['asgari_odeme'], $sonuc['alanlar']['donem_baslangic'],
        ]);
        $this->assertSame(['8085.68', '7736.49'], [$sonuc['ozet']['onceki_borc'], $sonuc['ozet']['toplam_satiri']]);
        $this->assertCount(4, $sonuc['satirlar']);
        [$odeme, $harcama, $iade, $taksit] = $sonuc['satirlar'];
        $this->assertSame(['odeme', '8085.68', true], [$odeme['tur'], $odeme['tutar'], $odeme['alacak']]);
        // Puan sütunu tutar değil; açıklamadaki çoklu boşluk tek boşluk
        $this->assertSame(['HEPSIPAY *HEPSIBURADA ISTANBUL TR', '211.66', 'harcama', []], [$harcama['aciklama'], $harcama['tutar'], $harcama['tur'], $harcama['okuma']['tutar_adaylari']]);
        $this->assertSame(['iade', '115.42'], [$iade['tur'], $iade['tutar']]);
        // Kalan / N ve alt satırdaki "T TL'lik işlemin n / N taksidi"
        $this->assertSame(['1757.80', '10546.80', 3, 9, '15820.17'], [$taksit['tutar'], $taksit['taksit_kalan'], $taksit['taksit_no'], $taksit['taksit_sayisi'], $taksit['taksit_toplam']]);
    }

    public function test_enpara(): void
    {
        $sonuc = $this->ayristir([
            "Ekstre tarihi\t10/11/2026\tAd soyad\tAD SOYAD",
            "Ekstre borcu\t3.205,35 TL\tKart numarası\t5269 11** **** 1260",
            "Minimum ödeme tutarı\t642,00 TL\tKart limiti\t40.000,00 TL",
            "Son ödeme tarihi\t20/11/2026\tKullanılabilir kart limiti\t36.794,65 TL",
            "İşlem tarihi\tAçıklama\tTaksit\tTutar",
            "Bir önceki ekstre bakiyeniz\t2.081,00 TL",
            "21/10/2026\tÖdeme - Enpara.com Cep Şubesi\t- 2.081,00 TL",
            "12/10/2026\t333360 - CORUMGAZ - ODEME\t1.090,00 TL",
            "10/11/2026\tFaizlerin KKDF’si*\t3,73 TL",
            '* BSMV ve KKDF tutarlarına varsa faizli olarak taksitlendirdiğiniz harcamaların',
            "Bir sonraki ekstrenizin tarihi 10/12/2026 , son ödeme tarihi ise 21/12/2026 'dır.",
            'Enpara Bank A.Ş. Büyük Mükellefler V.D.',
        ]);

        $this->assertSame('enpara', $sonuc['profil']);
        $this->assertSame(['2026-11-10', '2026-11-20', '3205.35', '642.00'], [
            $sonuc['alanlar']['kesim_tarihi'], $sonuc['alanlar']['son_odeme_tarihi'], $sonuc['alanlar']['donem_borcu'], $sonuc['alanlar']['asgari_odeme'],
        ]);
        $this->assertSame('2081.00', $sonuc['ozet']['onceki_borc']);
        $this->assertSame(['odeme', 'harcama', 'vergi'], array_column($sonuc['satirlar'], 'tur'));
        $this->assertSame(['2081.00', true], [$sonuc['satirlar'][0]['tutar'], $sonuc['satirlar'][0]['alacak']]);
    }
}
