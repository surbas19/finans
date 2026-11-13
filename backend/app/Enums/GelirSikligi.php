<?php

namespace App\Enums;

/**
 * Düzenli gelirin tekrarı; değer kaç ayda bir geldiği.
 */
enum GelirSikligi: string
{
    case Aylik = 'aylik';
    case IkiAylik = 'iki_aylik';
    case UcAylik = 'uc_aylik';
    case AltiAylik = 'alti_aylik';
    case Yillik = 'yillik';

    public function ay(): int
    {
        return match ($this) {
            self::Aylik => 1,
            self::IkiAylik => 2,
            self::UcAylik => 3,
            self::AltiAylik => 6,
            self::Yillik => 12,
        };
    }

    public function etiket(): string
    {
        return match ($this) {
            self::Aylik => 'Her ay',
            self::IkiAylik => '2 ayda bir',
            self::UcAylik => '3 ayda bir',
            self::AltiAylik => '6 ayda bir',
            self::Yillik => 'Yılda bir',
        };
    }
}
