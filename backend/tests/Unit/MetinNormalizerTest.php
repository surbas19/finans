<?php

namespace Tests\Unit;

use App\Services\Ekstre\MetinNormalizer;
use PHPUnit\Framework\TestCase;

class MetinNormalizerTest extends TestCase
{
    public function test_kod_sayfasi_ve_ay_adlari(): void
    {
        $n = new MetinNormalizer;
        // CP1254 metin Latin-1 gibi çözülmüş
        $this->assertSame('İşlem Tarihi Dönem İçi İşlemler MAĞAZA', $n->satir('Ýþlem Tarihi Dönem Ýçi Ýþlemler MAÐAZA'));
        $this->assertSame('22.10.2026 SANLI LAHMACUN', $n->satir('22 Ekim 2026 SANLI LAHMACUN'));
        $this->assertSame('Son Ödeme Tarihi 20.11.2026', $n->satir('Son Ödeme Tarihi 20 Kasým 2026'));
        $this->assertSame('01.08.2026 ve 05.02.2026', $n->satir('1 AĞUSTOS 2026 ve 5 Şub 2026'));
        $this->assertSame('11.12.2026 Cuma', $n->satir('11 Aralık 2026 Cuma'));
        // Ay adı değilse ve sayısal tarihse dokunulmaz
        $this->assertSame('12 adet 2026', $n->satir('12 adet 2026'));
        $this->assertSame('10.11.2026 MARKET', $n->satir('10.11.2026 MARKET'));
        $this->assertSame([['a', '03.03.2026']], $n->normalize([['a', '3 Mart 2026']]));
    }
}
