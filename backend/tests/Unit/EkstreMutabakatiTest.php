<?php

namespace Tests\Unit;

use App\Services\Ekstre\EkstreMutabakati;
use PHPUnit\Framework\TestCase;

class EkstreMutabakatiTest extends TestCase
{
    private function satir(string $tur, string $tutar): array
    {
        return ['tur' => $tur, 'tutar' => $tutar];
    }

    public function test_borc_denklemi_tutuyor(): void
    {
        // 500 − 500 (ödeme) − 50 (iade) + 400 (harcama) + 25,50 (faiz) + 124,50 (harcama) = 500
        $r = (new EkstreMutabakati)->denetle(
            ['donem_borcu' => '500.00'],
            ['onceki_borc' => '500.00'],
            [$this->satir('odeme', '500.00'), $this->satir('iade', '50.00'), $this->satir('harcama', '400.00'), $this->satir('ucret', '25.50'), $this->satir('harcama', '124.50')],
        );

        $this->assertSame(['tutuyor', null], [$r['durum'], $r['fark']]);
        $this->assertSame('tutuyor', $r['kontroller'][0]['durum']);
        $this->assertSame(['harcama' => '524.50', 'faiz_ucret' => '25.50', 'nakit_avans' => '0.00', 'odeme' => '500.00', 'iade' => '50.00', 'bonus' => '0.00'], $r['toplamlar']);
    }

    public function test_bonus_borcu_duser_faiz_ve_vergi_ucretlerle(): void
    {
        // 1000 önceki − 1000 ödeme − 60 bonus + 900 harcama + 40 faiz + 6 vergi + 4 ücret = 890
        $r = (new EkstreMutabakati)->denetle(
            ['donem_borcu' => '890.00'],
            ['onceki_borc' => '1000.00'],
            [$this->satir('odeme', '1000.00'), $this->satir('bonus', '60.00'), $this->satir('harcama', '900.00'), $this->satir('faiz', '40.00'), $this->satir('vergi', '6.00'), $this->satir('ucret', '4.00')],
        );

        $this->assertSame('tutuyor', $r['durum']);
        $this->assertSame(['50.00', '60.00'], [$r['toplamlar']['faiz_ucret'], $r['toplamlar']['bonus']]);
    }

    public function test_tutmuyorsa_fark_kurusuna_gorunur(): void
    {
        $r = (new EkstreMutabakati)->denetle(
            ['donem_borcu' => '1000.00'],
            ['onceki_borc' => '0.00', 'harcama_toplami' => '1000.00'],
            [$this->satir('harcama', '600.00'), $this->satir('harcama', '399.50')],
        );

        $this->assertSame('tutmuyor', $r['durum']);
        $this->assertSame('-0.50', $r['fark']);
        $this->assertSame(['borc_denklemi', 'harcama_toplami'], array_column($r['kontroller'], 'ad'));
        $this->assertSame(['999.50', '1000.00', '-0.50'], [$r['kontroller'][0]['bulunan'], $r['kontroller'][0]['beklenen'], $r['kontroller'][0]['fark']]);
    }

    public function test_okunamayan_deger_uydurulmaz(): void
    {
        $r = (new EkstreMutabakati)->denetle(['donem_borcu' => '1000.00'], [], [$this->satir('harcama', '1000.00')]);
        $this->assertSame('kontrol_edilemedi', $r['durum']);
        $this->assertSame('Önceki dönem borcu okunamadı.', $r['kontroller'][0]['neden']);

        $r = (new EkstreMutabakati)->denetle([], ['onceki_borc' => '10.00'], []);
        $this->assertSame('Dönem borcu okunamadı.', $r['kontroller'][0]['neden']);
        // Tutarı okunamayan satır toplamlara girmez
        $r = (new EkstreMutabakati)->denetle(['donem_borcu' => '5.00'], ['onceki_borc' => '0.00'], [$this->satir('harcama', '5.00'), ['tur' => 'harcama', 'tutar' => null]]);
        $this->assertSame('tutuyor', $r['durum']);
    }
}
