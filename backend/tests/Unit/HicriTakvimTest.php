<?php

namespace Tests\Unit;

use App\Models\ZekatAyari;
use App\Services\Zekat\ZekatTakvimi;
use App\Support\HicriTakvim;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Hicrî takvim: `intl` (Ümmülkura) ve saf PHP yedek algoritması. Yıl 354 gün sabitiyle ilerletilmez.
 */
class HicriTakvimTest extends TestCase
{
    protected function tearDown(): void
    {
        HicriTakvim::$intlKullan = null;
        parent::tearDown();
    }

    public function test_ramazan_baslangici_ve_sonraki_yil_yaklasik_11_gun_erken(): void
    {
        foreach ([null, false] as $intl) {
            HicriTakvim::$intlKullan = $intl;
            if ($intl === null && ! HicriTakvim::intlVar()) {
                continue;
            }
            $bu = HicriTakvim::miladiye(1447, 9, 1);
            $sonraki = HicriTakvim::miladiye(1448, 9, 1);
            // 1 Ramazan 1447 ≈ 18 Şubat 2026 (yedek algoritma ±1 gün sapabilir)
            $this->assertLessThanOrEqual(1, abs($bu->diffInDays(Carbon::create(2026, 2, 18), true)), 'yöntem: '.HicriTakvim::yontem());
            // Hicrî yıl 354 / 355 gün: miladî yılın ~10-11 gün gerisinde
            $fark = (int) $bu->diffInDays($sonraki);
            $this->assertContains($fark, [353, 354, 355, 356]);
            $this->assertLessThan(Carbon::create(2027, 2, 18), $sonraki);
        }
    }

    public function test_sonraki_zekat_gunu_bugunden_sonra_gelir_ve_her_yil_yeniden_hesaplanir(): void
    {
        foreach ([null, false] as $intl) {
            HicriTakvim::$intlKullan = $intl;
            $sonraki = HicriTakvim::sonraki(9, 1, Carbon::create(2026, 11, 15));
            $this->assertLessThanOrEqual(1, abs($sonraki->diffInDays(Carbon::create(2027, 2, 8), true)));
            // Bugün zekât günüyse bugün döner
            $this->assertTrue(HicriTakvim::sonraki(9, 1, $sonraki)->isSameDay($sonraki));
            // Ertesi günden bakınca bir sonraki hicrî yıl; takvim sabit 354 günle kaymaz
            $sonrakiYil = HicriTakvim::sonraki(9, 1, $sonraki->copy()->addDay());
            $this->assertContains((int) $sonraki->diffInDays($sonrakiYil), [354, 355]);
        }
    }

    public function test_yedek_algoritma_intl_ile_en_fazla_iki_gun_ayrisir_ve_gidis_donus_tutarli(): void
    {
        if (! class_exists(\IntlCalendar::class)) {
            $this->markTestSkipped('intl yok; yedek algoritma yukarıdaki testlerde doğrulandı.');
        }
        $ayriGun = 0;
        for ($yil = 1445; $yil <= 1460; $yil++) {
            for ($ay = 1; $ay <= 12; $ay++) {
                HicriTakvim::$intlKullan = true;
                $icu = HicriTakvim::miladiye($yil, $ay, 1);
                HicriTakvim::$intlKullan = false;
                $yedek = HicriTakvim::miladiye($yil, $ay, 1);
                $fark = (int) $icu->diffInDays($yedek, true);
                // Ümmülkura ay başları aritmetik tablodan en çok 2 gün ayrışır
                $this->assertLessThanOrEqual(2, $fark, "{$yil}-{$ay}");
                $ayriGun += $fark;
                // Gidiş-dönüş (yedek)
                $this->assertSame([$yil, $ay, 1], HicriTakvim::hicriye($yedek));
            }
        }
        $this->assertLessThan(192, $ayriGun);
    }

    public function test_intl_olmadan_yedek_yontem_kullanilir_ve_adi_yedektir(): void
    {
        HicriTakvim::$intlKullan = false;
        $this->assertSame('yedek', HicriTakvim::yontem());
        $tarih = HicriTakvim::miladiye(1448, 9, 1);
        $this->assertSame([1448, 9, 1], HicriTakvim::hicriye($tarih));
        // 29 çeken ayda 30. gün ayın son gününe iner
        $son = HicriTakvim::miladiye(1448, 2, 30);
        $this->assertSame(1448, HicriTakvim::hicriye($son)[0]);
        $this->assertSame(2, HicriTakvim::hicriye($son)[1]);
    }

    public function test_zekat_takvimi_miladi_gun_ve_subat_29_son_gune_iner(): void
    {
        $ayar = new ZekatAyari(['zekat_gunu_turu' => 'miladi', 'miladi_ay' => 2, 'miladi_gun' => 29]);
        $takvim = new ZekatTakvimi;
        $this->assertSame('2027-02-28', $takvim->ilk($ayar, Carbon::create(2026, 11, 15))['tarih']->toDateString());
        $this->assertSame('2028-02-29', $takvim->sonrasi($ayar, Carbon::create(2027, 2, 28))['tarih']->toDateString());
        $this->assertFalse($takvim->ilk($ayar, Carbon::create(2026, 11, 15))['tahmini']);
    }

    public function test_zekat_takvimi_hicri_tahmini_isaretlenir(): void
    {
        $ayar = new ZekatAyari(['zekat_gunu_turu' => 'hicri', 'hicri_ay' => 9, 'hicri_gun' => 1]);
        $r = (new ZekatTakvimi)->ilk($ayar, Carbon::create(2026, 11, 15));
        $this->assertTrue($r['tahmini']);
        $this->assertContains($r['yontem'], ['intl', 'yedek']);
    }
}
