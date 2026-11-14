<?php

namespace App\Services\AIStatement;

/**
 * Yapay zekâdan dönen JSON'u PHP tarafında şemaya karşı yeniden doğrular (model "strict" demiş olsa da güvenilmez):
 * type (tek ya da birden çok), enum, required, additionalProperties=false, items. Bağımlılık eklememek için küçük,
 * yalnızca bu şemanın kullandığı kuralları bilen bir doğrulayıcı.
 */
class JsonValidator
{
    /**
     * @param  array<string, mixed>  $sema
     * @return list<string> hata yolları ("transactions.3.amount: sayı olmalı"); boşsa geçerli
     */
    public function dogrula(mixed $veri, array $sema, string $yol = ''): array
    {
        $hatalar = [];
        $turler = (array) ($sema['type'] ?? []);
        if ($turler !== [] && ! $this->turUyuyor($veri, $turler)) {
            return [($yol ?: 'kök').': beklenen tür '.implode('|', $turler)];
        }
        if (isset($sema['enum']) && ! in_array($veri, $sema['enum'], true)) {
            $hatalar[] = ($yol ?: 'kök').': izin verilen değerlerden biri olmalı';
        }
        if (is_array($veri) && ($sema['type'] ?? null) === 'object' || (is_array($veri) && in_array('object', $turler, true))) {
            foreach ($sema['required'] ?? [] as $alan) {
                if (! array_key_exists($alan, $veri)) {
                    $hatalar[] = ($yol !== '' ? "{$yol}." : '')."{$alan}: eksik";
                }
            }
            if (($sema['additionalProperties'] ?? true) === false) {
                foreach (array_diff(array_keys($veri), array_keys($sema['properties'] ?? [])) as $fazla) {
                    $hatalar[] = ($yol !== '' ? "{$yol}." : '')."{$fazla}: şemada yok";
                }
            }
            foreach ($sema['properties'] ?? [] as $alan => $altSema) {
                if (array_key_exists($alan, $veri)) {
                    array_push($hatalar, ...$this->dogrula($veri[$alan], $altSema, ($yol !== '' ? "{$yol}." : '').$alan));
                }
            }
        }
        if (is_array($veri) && in_array('array', $turler, true) && isset($sema['items'])) {
            foreach ($veri as $i => $oge) {
                array_push($hatalar, ...$this->dogrula($oge, $sema['items'], ($yol !== '' ? "{$yol}." : '').$i));
            }
        }

        return $hatalar;
    }

    /**
     * @param  list<string>  $turler
     */
    private function turUyuyor(mixed $veri, array $turler): bool
    {
        foreach ($turler as $tur) {
            $uyar = match ($tur) {
                'null' => $veri === null,
                'string' => is_string($veri),
                'boolean' => is_bool($veri),
                'integer' => is_int($veri) || (is_float($veri) && floor($veri) === $veri),
                'number' => is_int($veri) || is_float($veri),
                'array' => is_array($veri) && array_is_list($veri),
                'object' => is_array($veri) && ($veri === [] || ! array_is_list($veri)),
                default => false,
            };
            if ($uyar) {
                return true;
            }
        }

        return false;
    }
}
