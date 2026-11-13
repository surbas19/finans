<?php

namespace App\Services\Portfoy;

use App\Enums\IslemTuru;
use App\Models\Islem;

/**
 * Bir hesaptaki bir varlığın durumu, işlemler tarih sırasıyla uygulanarak bulunur (ağırlıklı ortalama maliyet).
 *
 * Maliyeti bilinen ve bilinmeyen miktar ayrı tutulur: bilinmeyen sıfır maliyet sayılmaz. Satış iki kısımdan
 * oranla düşer; bilinmeyen kısmın kârı / zararı hesaplanamaz (gerceklesenEksik). Tutarlar TL, komisyon dahil.
 */
final class Pozisyon
{
    public const EPS = 1e-9;

    public float $miktarBilinen = 0.0;

    public float $maliyetBilinen = 0.0;

    public float $miktarBilinmeyen = 0.0;

    public float $gerceklesen = 0.0;

    public bool $gerceklesenEksik = false;

    public bool $satisVar = false;

    public function __construct(public readonly int $hesapId, public readonly int $varlikId) {}

    public function miktar(): float
    {
        return $this->miktarBilinen + $this->miktarBilinmeyen;
    }

    /**
     * @throws YetersizMiktar
     */
    public function uygula(Islem $islem): void
    {
        $miktar = (float) $islem->miktar;
        $komisyon = (float) $islem->komisyon;

        if ($islem->tur !== IslemTuru::Satis) {
            if ($islem->birim_fiyat !== null) {
                $this->miktarBilinen += $miktar;
                $this->maliyetBilinen += $miktar * (float) $islem->birim_fiyat + $komisyon;
            } else {
                $this->miktarBilinmeyen += $miktar;
            }

            return;
        }

        $eldeki = $this->miktar();
        if ($miktar > $eldeki + self::EPS) {
            throw new YetersizMiktar($islem, $eldeki);
        }
        $this->satisVar = true;
        $oran = $eldeki > 0 ? min(1.0, $miktar / $eldeki) : 0.0;
        $satilanBilinen = $this->miktarBilinen * $oran;
        $satilanBilinmeyen = $this->miktarBilinmeyen * $oran;
        $dusenMaliyet = $this->maliyetBilinen * $oran;

        if ($islem->birim_fiyat !== null && $miktar > 0) {
            $gelir = $miktar * (float) $islem->birim_fiyat - $komisyon;
            $this->gerceklesen += $gelir * ($satilanBilinen / $miktar) - $dusenMaliyet;
            if ($satilanBilinmeyen > self::EPS) {
                $this->gerceklesenEksik = true;
            }
        } else {
            $this->gerceklesenEksik = true;
        }

        $this->miktarBilinen = self::sifirla($this->miktarBilinen - $satilanBilinen);
        $this->miktarBilinmeyen = self::sifirla($this->miktarBilinmeyen - $satilanBilinmeyen);
        $this->maliyetBilinen = $this->miktarBilinen > 0 ? max(0.0, $this->maliyetBilinen - $dusenMaliyet) : 0.0;
    }

    public function ortalamaMaliyet(): ?float
    {
        return $this->miktarBilinen > self::EPS ? $this->maliyetBilinen / $this->miktarBilinen : null;
    }

    private static function sifirla(float $deger): float
    {
        return abs($deger) < self::EPS ? 0.0 : $deger;
    }
}
