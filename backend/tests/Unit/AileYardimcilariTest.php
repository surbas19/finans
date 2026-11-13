<?php

namespace Tests\Unit;

use App\Services\Aile\DovizKuru;
use App\Support\Donem;
use App\Support\Kurus;
use App\Support\Metin;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AileYardimcilariTest extends TestCase
{
    public function test_kurus_kayan_nokta_olmadan(): void
    {
        $this->assertSame(123450, Kurus::kurus('1234.5'));
        $this->assertSame(123456, Kurus::kurus('1234.56'));
        $this->assertSame(123456, Kurus::kurus('1234.560'));
        $this->assertSame(-1050, Kurus::kurus('-10.50'));
        $this->assertSame(1000, Kurus::kurus(10));
        $this->assertSame(30, Kurus::kurus(0.3));
        $this->assertSame(0, Kurus::kurus(null));
        // 0.1 + 0.2 kuruşla tam
        $this->assertSame('0.30', Kurus::decimal(Kurus::kurus('0.1') + Kurus::kurus('0.2')));
        $this->assertSame('-0.05', Kurus::decimal(-5));
        $this->assertSame(1234.5, Kurus::tl(123450));

        $this->expectException(InvalidArgumentException::class);
        Kurus::kurus('1.234');
    }

    public function test_doviz_carpimi_kurusla_ve_tasmadan(): void
    {
        $this->assertSame(325000, DovizKuru::tlKurus(10000, '32.500000'));
        // 1.234,57 USD × 34,123456 = 42.127,795 → 42.127,80 (yarım kuruş yukarı)
        $this->assertSame(4212780, DovizKuru::tlKurus(123457, '34.123456'));
        $this->assertSame('3250000000.00', DovizKuru::tlDecimal('100000000.00', '32.500000'));
    }

    public function test_donem_ve_ay_adi(): void
    {
        $this->assertTrue(Donem::gecerli('2026-01'));
        $this->assertFalse(Donem::gecerli('2026-13'));
        $this->assertFalse(Donem::gecerli('2026-1'));
        $this->assertSame('2026-02', Donem::ekle('2026-01', 1));
        $this->assertSame('2025-12', Donem::ekle('2026-01', -1));
        $this->assertSame(14, Donem::fark('2025-11', '2027-01'));
        $this->assertSame('Şubat 2026', Donem::etiket('2026-02'));
        $this->assertSame(2, Donem::ayNumarasi('ŞUBAT'));
        $this->assertSame(2, Donem::ayNumarasi('subat'));
        $this->assertSame(8, Donem::ayNumarasi('Ağustos'));
        $this->assertNull(Donem::ayNumarasi('Ocakk'));
    }

    public function test_metin_sadelestirme_ve_kelime_siniri(): void
    {
        $this->assertSame('ISKI SU IDARESI ISTANBUL', Metin::sade("  İski su   idaresi\tİstanbul "));
        $this->assertSame('IGDAS DOGALGAZ CEKIM', Metin::sade('İGDAŞ doğalgaz çekim'));
        $this->assertTrue(Metin::geciyor('ENERJISA BASKENT EDAS OTOMATIK ODEME', 'EDAS'));
        $this->assertFalse(Metin::geciyor('KEDASX MAGAZA', 'EDAS'));
        $this->assertTrue(Metin::geciyor('ISKI*FATURA', 'ISKI'));
        $this->assertFalse(Metin::geciyor('ISKI', ''));
    }
}
