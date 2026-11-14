<?php

namespace Tests\Unit;

use App\Services\AIStatement\KisiselVeriTemizleyici;
use PHPUnit\Framework\TestCase;

class KisiselVeriTemizleyiciTest extends TestCase
{
    public function test_kisisel_satirlar_silinir_maskelenir_islem_satirlarina_dokunulmaz(): void
    {
        $temiz = (new KisiselVeriTemizleyici)->temizle([
            "Ad Soyad\tALI VELI",
            'Sn. ALI VELI',
            'Meydan Mah. Tuzcu Sk. NO: 1 KAPI NO: 6 ISKILIP / CORUM',
            "Kart Numarası\t: 4506 34** **** 4589\tAYSE YILMAZ",
            "Kart Numarasý 9792 29** **** 1029\tSon Ödeme Tarihi 20.11.2026",
            'Müşteri Numarası 6601309',
            'IBAN TR12 3456 7890 1234 5678 9012 34',
            'Havale TR120006200000000012345678 EFT',
            'Kart sahibinin T.C. kimlik numarası 12345678901',
            'Ref 12345678901 mail ali@ornek.com tel 05321234567',
            "15.10.2026\tMIGROS KADIKOY\t1.234,56",
            "28.10.2026 TAHIR VE CEVDET\t45,00",
            "26.10.2026 -AVEA-5052545854202622909\t458,75",
        ]);

        $this->assertSame([
            "Kart Numarası\t: [KART]",
            "Kart Numarasý [KART]\tSon Ödeme Tarihi 20.11.2026",
            'Havale [IBAN] EFT',
            'Ref [KIMLIK] mail [E-POSTA] tel [TELEFON]',
            "15.10.2026\tMIGROS KADIKOY\t1.234,56",
            "28.10.2026 TAHIR VE CEVDET\t45,00",
            // Uzun referans numarası (19 hane) kimlik sanılmaz
            "26.10.2026 -AVEA-5052545854202622909\t458,75",
        ], $temiz);
    }
}
