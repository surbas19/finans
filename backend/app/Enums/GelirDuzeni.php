<?php

namespace App\Enums;

/**
 * Raporlarda düzenli gelir, düzensiz ve tek seferlik gelirden ayrı gösterilir.
 */
enum GelirDuzeni: string
{
    case Duzenli = 'duzenli';
    case Duzensiz = 'duzensiz';
    case TekSeferlik = 'tek_seferlik';

    public function etiket(): string
    {
        return match ($this) {
            self::Duzenli => 'Düzenli',
            self::Duzensiz => 'Düzensiz',
            self::TekSeferlik => 'Tek seferlik',
        };
    }
}
