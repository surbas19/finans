<?php

namespace Tests\Unit;

use App\Services\Ekstre\BankaTanici;
use App\Services\Ekstre\EkstreAyristirici;
use App\Services\Ekstre\MetinNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Genel ayrıştırıcı: farklı satır biçimleri, dönem bilgileri, taksit, alacak, okunamayan alan (uydurulmaz).
 * Banka profilleri `EkstreProfilleriTest`'te.
 */
class EkstreAyristiriciTest extends TestCase
{
    private function ayristir(array ...$sayfalar): array
    {
        return (new EkstreAyristirici(new MetinNormalizer, new BankaTanici))->ayristir($sayfalar);
    }

    public function test_donem_bilgileri_ve_islem_satirlari(): void
    {
        $sonuc = $this->ayristir([
            'ORNEK BANKA - Kredi Kartı Hesap Özeti',
            'Önceki Hesap Kesim Tarihi: 10.10.2026',
            "Hesap Kesim Tarihi: 10.11.2026\tSon Ödeme Tarihi: 20.11.2026",
            'Önceki Dönem Borcu 3.000,00 TL',
            'Dönem Borcu 12.345,67 TL',
            "Asgari Ödeme Tutarı\t2.469,13",
            "15.10.2026\tMİGROS KADIKÖY İSTANBUL\t1.234,56",
            '16/10/2026 ENERJISA BASKENT EDAS OTOMATIK ODEME 845,10 TL',
            '20.10.26 ÖDEMENİZ İÇİN TEŞEKKÜRLER -5.000,00',
            '22.10.2026 TEKNOSA ISTANBUL 3/6 TAKSIT 1.000,00',
            '23.10.2026 23.10.2026 ZARA IADE 250,00 +',
            '25.10.2026 KKDF 12,34',
            '26.10.2026 SHELL ATASEHIR 1.500,00 15,00',
            '27.10.2026 - 10.11.2026',
            'Sayfa 1 / 2',
        ]);

        $this->assertSame('genel', $sonuc['profil']);
        $this->assertSame([
            'kesim_tarihi' => '2026-11-10',
            'son_odeme_tarihi' => '2026-11-20',
            'donem_baslangic' => '2026-10-10',
            'donem_borcu' => '12345.67',
            'asgari_odeme' => '2469.13',
        ], $sonuc['alanlar']);
        $this->assertSame('3000.00', $sonuc['ozet']['onceki_borc']);
        $this->assertSame([], $sonuc['okunamayan']);

        $satirlar = $sonuc['satirlar'];
        $this->assertCount(7, $satirlar);
        $this->assertSame(['2026-10-15', 'MİGROS KADIKÖY İSTANBUL', '1234.56', 'harcama', false], [$satirlar[0]['islem_tarihi'], $satirlar[0]['aciklama'], $satirlar[0]['tutar'], $satirlar[0]['tur'], $satirlar[0]['alacak']]);
        // Borç olan "OTOMATIK ODEME" fatura ödemesidir (harcama), kart borcu ödemesi değil
        $this->assertSame(['2026-10-16', 'harcama', '845.10'], [$satirlar[1]['islem_tarihi'], $satirlar[1]['tur'], $satirlar[1]['tutar']]);
        $this->assertSame(['2026-10-20', 'odeme', '5000.00', true], [$satirlar[2]['islem_tarihi'], $satirlar[2]['tur'], $satirlar[2]['tutar'], $satirlar[2]['alacak']]);
        $this->assertSame([3, 6, 'harcama'], [$satirlar[3]['taksit_no'], $satirlar[3]['taksit_sayisi'], $satirlar[3]['tur']]);
        // İkinci tarih (valör) açıklamaya girmez; "+" alacak → iade
        $this->assertSame(['ZARA IADE', 'iade', true], [$satirlar[4]['aciklama'], $satirlar[4]['tur'], $satirlar[4]['alacak']]);
        $this->assertSame('vergi', $satirlar[5]['tur']);
        // İki tutar: genel profilde soldaki alınır, uyarılır, adaylar saklanır
        $this->assertSame('1500.00', $satirlar[6]['tutar']);
        $this->assertSame(['1500.00', '15.00'], $satirlar[6]['okuma']['tutar_adaylari']);
        $this->assertStringContainsString('birden çok tutar', $satirlar[6]['okuma']['uyarilar'][0]);
        $this->assertSame(1, $satirlar[0]['sayfa']);
        $this->assertSame("15.10.2026\tMİGROS KADIKÖY İSTANBUL\t1.234,56", $satirlar[0]['ham']);
    }

    public function test_onde_arti_ve_bosluklu_eksi_alacak_ay_adli_tarih(): void
    {
        $sonuc = $this->ayristir([
            'Hesap Kesim Tarihi 13 Ekim 2026',
            "21 Eylül 2026 ÖDEME-İNTERNET BANKACILIĞI\t+8.085,68",
            "21/10/2026\tÖdeme - Cep Şubesi\t- 2.081,00 TL",
            "10/11/2026\tAlışveriş faizi\t23,90 TL",
            "26 Ekim 2026 OTOM. FATURA İŞLEM FAİZİ\t19,39",
        ]);

        $this->assertSame('2026-10-13', $sonuc['alanlar']['kesim_tarihi']);
        $this->assertSame(['2026-09-21', 'odeme', '8085.68', true], [$sonuc['satirlar'][0]['islem_tarihi'], $sonuc['satirlar'][0]['tur'], $sonuc['satirlar'][0]['tutar'], $sonuc['satirlar'][0]['alacak']]);
        $this->assertSame(['odeme', '2081.00', true], [$sonuc['satirlar'][1]['tur'], $sonuc['satirlar'][1]['tutar'], $sonuc['satirlar'][1]['alacak']]);
        $this->assertSame(['faiz', 'faiz'], [$sonuc['satirlar'][2]['tur'], $sonuc['satirlar'][3]['tur']]);
    }

    public function test_bulunamayan_alan_ve_tarih_uydurulmaz(): void
    {
        $sonuc = $this->ayristir(['15.10 MARKET 100,00', '31.02.2026 HATALI TARIH 50,00']);

        // Kesim tarihi yok: yılsız tarih okunamaz; 31 Şubat okunamaz; dönem alanları okunamadı
        $this->assertSame(['kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'], $sonuc['okunamayan']);
        $this->assertNull($sonuc['satirlar'][0]['islem_tarihi']);
        $this->assertSame(['islem_tarihi'], $sonuc['satirlar'][0]['okuma']['alanlar']);
        $this->assertNull($sonuc['satirlar'][1]['islem_tarihi']);
    }

    public function test_yilsiz_tarih_kesimden_ve_alt_satirdaki_degerler(): void
    {
        $sonuc = $this->ayristir([
            "Hesap Kesim Tarihi\tSon Ödeme Tarihi\tDönem Borcu\tAsgari Ödeme",
            "10.01.2027\t20.01.2027\t4.000,00\t800,00",
            '28.12 YILBASI ALISVERIS 300,00',
            '05.01 MARKET 100,00',
        ]);

        $this->assertSame(['2027-01-10', '2027-01-20', '4000.00', '800.00'], [
            $sonuc['alanlar']['kesim_tarihi'], $sonuc['alanlar']['son_odeme_tarihi'], $sonuc['alanlar']['donem_borcu'], $sonuc['alanlar']['asgari_odeme'],
        ]);
        // Aralık harcaması Ocak ekstresinde: geçen yıl
        $this->assertSame('2026-12-28', $sonuc['satirlar'][0]['islem_tarihi']);
        $this->assertSame('2027-01-05', $sonuc['satirlar'][1]['islem_tarihi']);
        $this->assertSame(['Yıl ekstre döneminden alındı.'], $sonuc['satirlar'][1]['okuma']['uyarilar']);
    }

    public function test_taksit_ve_tur_tahminleri(): void
    {
        $a = new EkstreAyristirici(new MetinNormalizer, new BankaTanici);
        $this->assertSame([2, 6], $a->taksit('XYZ MAGAZA (2/6)'));
        $this->assertSame([3, 9], $a->taksit('TAKSIT 3/9 ABC'));
        $this->assertSame([4, 12], $a->taksit('ABC 4. TAKSIT / 12'));
        $this->assertSame([1, 3], $a->taksit('MAGAZA 1/3'));
        $this->assertSame([null, null], $a->taksit('MAGAZA 7/3'));
        $this->assertSame([null, null], $a->taksit('APT NO 12/45 ISTANBUL'));
        $this->assertSame('nakit_avans', $a->tur('ATM NAKIT AVANS', false)->value);
        $this->assertSame('transfer', $a->tur('INTERNET HAVALE', false)->value);
        $this->assertSame('iade', $a->tur('ABC MAGAZA', true)->value);
        $this->assertSame('odeme', $a->tur('HESAPTAN ODEME', true)->value);
        $this->assertSame('faiz', $a->tur('ALISVERIS FAIZI', false)->value);
        $this->assertSame('faiz', $a->tur('GECIKME FAIZI', false)->value);
        $this->assertSame('vergi', $a->tur('KKDF', false)->value);
        $this->assertSame('vergi', $a->tur('BSMV TUTARI', false)->value);
        $this->assertSame('ucret', $a->tur('KART AIDATI', false)->value);
        $this->assertSame('ucret', $a->tur('GECIKME UCRETI', false)->value);
        // Alacak bonus / puan kullanımı iade değil, bonus (borcu düşürür, gider sayılmaz)
        $this->assertSame('bonus', $a->tur('BONUS BEDAVA ALISVERIS', true)->value);
        $this->assertSame('bonus', $a->tur('WORLDPUAN KULLANIMI', true)->value);
        $this->assertSame('harcama', $a->tur('FAIZSIZ MARKET', false)->value);
    }
}
