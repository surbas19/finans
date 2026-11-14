<?php

namespace Tests\Unit;

use App\Services\Ekstre\EkstreTeshisi;
use App\Services\Ekstre\MetinNormalizer;
use PHPUnit\Framework\TestCase;

class EkstreTeshisiTest extends TestCase
{
    private function teshis(): EkstreTeshisi
    {
        return new EkstreTeshisi(new MetinNormalizer);
    }

    public function test_maskeleme_rakam_sizdirmaz(): void
    {
        $m = EkstreTeshisi::maskele("2026-10-15  MARKET   KADIKOY\t1.234,56 TL kart 4506 34** **** 4589");
        $this->assertSame('####-##-## MARKET KADIKOY #.###,## TL kart #### ##** **** ####', $m);
        $this->assertDoesNotMatchRegularExpression('/\d/', $m);
        $this->assertSame(80, mb_strlen(EkstreTeshisi::maskele(str_repeat('A1 ', 100))));
    }

    public function test_satir_varsa_ve_banka_taninmissa_teshis_gerekmez(): void
    {
        $this->assertNull($this->teshis()->olustur([['x']], ['profil' => 'garanti', 'satirlar' => [['ham' => 'a']]]));
    }

    public function test_tarih_bicimi_desteklenmiyorsa_nedeni_soyler(): void
    {
        $t = $this->teshis()->olustur(
            [['ORNEK BANKA', '2026-10-15 MARKET KADIKOY 1.234,56', '2026-10-16 ENERJISA 845,10']],
            ['profil' => 'genel', 'satirlar' => []],
        );

        $this->assertTrue($t['banka_taninmadi']);
        $this->assertSame([1, 3, 0, 0], [$t['sayfa_sayisi'], $t['metin_satiri'], $t['tarihli_satir'], $t['okunan_satir']]);
        $this->assertStringContainsString('Banka tanınamadı', $t['nedenler'][0]);
        $this->assertStringContainsString('tarih biçimi desteklenmiyor', $t['nedenler'][1]);
        $this->assertSame(['####-##-## MARKET KADIKOY #.###,##', '####-##-## ENERJISA ###,##'], $t['ornekler']);
    }

    public function test_tarihli_satir_islem_sayilmadiysa_eksik_uyarisi(): void
    {
        $sayfa = ['15.10.2026 MARKET 100,00', '16.10.2026 ?? yalniz aciklama', 'Dönem borcu 100,00'];
        $t = $this->teshis()->olustur([$sayfa], ['profil' => 'genel', 'satirlar' => [['ham' => '15.10.2026 MARKET 100,00']]]);

        $this->assertSame([2, 1, 1], [$t['tarihli_satir'], $t['okunan_satir'], $t['okunmayan_tarihli_satir']]);
        $this->assertStringContainsString('1 tarihli satır işlem sayılmadı', $t['nedenler'][1]);
        $this->assertSame(['##.##.#### ?? yalniz aciklama'], $t['ornekler']);

        // Ay adlı tarih de tarihli sayılır (normalizasyondan sonra)
        $t = $this->teshis()->olustur([['22 Ekim 2026 MARKET 100,00']], ['profil' => 'genel', 'satirlar' => []]);
        $this->assertSame(1, $t['tarihli_satir']);
        $this->assertStringContainsString('okunabilir tutar / açıklama bulunamadı', $t['nedenler'][1]);
    }
}
