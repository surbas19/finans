<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use IntlCalendar;

/**
 * Hicrî takvim dönüşümü. PHP `intl` varsa ICU'nun Ümmülkura takvimi (`islamic-umalqura`) kullanılır; yoksa saf PHP
 * tablo (aritmetik) algoritması: Ümmülkura ay başlangıcından 1-2 gün sapabilir; Diyanet takvimi de bir gün ayrışabilir,
 * bu yüzden arayüz hicrî zekât gününü her zaman "tahminî" yazar ve tarih elle de girilebilir.
 * Hicrî yıl 354 / 355 gün sabitiyle ilerletilmez; her tarih hicrî yıl / ay / günden yeniden hesaplanır.
 */
final class HicriTakvim
{
    /** Testte `false` verilerek `intl` yokmuş gibi yedek algoritma denenir; `null` = otomatik */
    public static ?bool $intlKullan = null;

    /** Hicrî 1 Muharrem 1 (sivil başlangıç) Jülyen gün sayısı */
    private const BASLANGIC_JDN = 1948440;

    /** 1970-01-01'in Jülyen gün sayısı */
    private const UNIX_JDN = 2440588;

    public static function intlVar(): bool
    {
        return self::$intlKullan ?? class_exists(IntlCalendar::class);
    }

    /** "intl" (Ümmülkura) ya da "yedek" (aritmetik, ±1 gün) */
    public static function yontem(): string
    {
        return self::intlVar() ? 'intl' : 'yedek';
    }

    /** Hicrî tarihin miladî karşılığı; ayda o gün yoksa (29 çeken ay) ayın son günü */
    public static function miladiye(int $yil, int $ay, int $gun): Carbon
    {
        if (self::intlVar()) {
            $takvim = self::icu();
            $takvim->clear();
            $takvim->set(IntlCalendar::FIELD_YEAR, $yil);
            $takvim->set(IntlCalendar::FIELD_MONTH, $ay - 1);
            $takvim->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
            $takvim->set(IntlCalendar::FIELD_DAY_OF_MONTH, min($gun, (int) $takvim->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH)));

            return self::utcGunu((int) $takvim->getTime());
        }

        $gunSayisi = self::ayUzunlugu($yil, $ay);

        return self::jdndenTarih(self::jdn($yil, $ay, min($gun, $gunSayisi)));
    }

    /**
     * Miladî tarihin hicrî karşılığı.
     *
     * @return array{0: int, 1: int, 2: int} yıl, ay (1-12), gün
     */
    public static function hicriye(CarbonInterface $tarih): array
    {
        $utc = Carbon::create($tarih->year, $tarih->month, $tarih->day, 12, 0, 0, 'UTC');
        if (self::intlVar()) {
            $takvim = self::icu();
            $takvim->setTime($utc->getTimestamp() * 1000);

            return [(int) $takvim->get(IntlCalendar::FIELD_YEAR), (int) $takvim->get(IntlCalendar::FIELD_MONTH) + 1, (int) $takvim->get(IntlCalendar::FIELD_DAY_OF_MONTH)];
        }

        $jdn = intdiv($utc->getTimestamp(), 86400) + self::UNIX_JDN;
        $yil = intdiv(30 * ($jdn - self::BASLANGIC_JDN) + 10646, 10631);
        $ay = min(12, (int) ceil(($jdn - (29 + self::jdn($yil, 1, 1))) / 29.5) + 1);
        $ay = max(1, $ay);

        return [$yil, $ay, $jdn - self::jdn($yil, $ay, 1) + 1];
    }

    /** `$bugun` dahil, o günden sonraki ilk hicrî ay / gün tarihi */
    public static function sonraki(int $ay, int $gun, CarbonInterface $bugun): Carbon
    {
        $gunBasi = Carbon::create($bugun->year, $bugun->month, $bugun->day);
        $yil = self::hicriye($bugun)[0];
        for ($y = $yil; $y <= $yil + 2; $y++) {
            $aday = self::miladiye($y, $ay, $gun);
            if ($aday->gte($gunBasi)) {
                return $aday;
            }
        }

        return self::miladiye($yil + 3, $ay, $gun);
    }

    private static function icu(): IntlCalendar
    {
        return IntlCalendar::createInstance('UTC', 'en_US@calendar=islamic-umalqura');
    }

    /** UTC milisaniyesinin takvim günü (uygulamanın saat dilimine bağlı olmayan yerel gün) */
    private static function utcGunu(int $milisaniye): Carbon
    {
        $gun = Carbon::createFromTimestampUTC(intdiv($milisaniye, 1000));

        return Carbon::create($gun->year, $gun->month, $gun->day);
    }

    private static function jdn(int $yil, int $ay, int $gun): int
    {
        return $gun + (int) ceil(29.5 * ($ay - 1)) + ($yil - 1) * 354 + intdiv(3 + 11 * $yil, 30) + self::BASLANGIC_JDN - 1;
    }

    private static function ayUzunlugu(int $yil, int $ay): int
    {
        return $ay === 12
            ? self::jdn($yil + 1, 1, 1) - self::jdn($yil, 12, 1)
            : self::jdn($yil, $ay + 1, 1) - self::jdn($yil, $ay, 1);
    }

    private static function jdndenTarih(int $jdn): Carbon
    {
        return self::utcGunu(($jdn - self::UNIX_JDN) * 86400 * 1000);
    }
}
