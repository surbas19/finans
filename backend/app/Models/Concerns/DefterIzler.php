<?php

namespace App\Models\Concerns;

use App\Services\Aile\DefterGecmisi;

/**
 * Kapanmış aya ait kayıtta ekleme / düzeltme / silme Defter'in "sonradan değişiklikler"ine yazılır (`DefterGecmisi`).
 * Model `defterBilgisi()` verir: ['hane_id', 'donem', 'tur', 'baslik', 'tarih', 'tutar', 'kayit_id', 'not'] ya da izlenmeyecekse null.
 */
trait DefterIzler
{
    /** @return array<string, mixed>|null */
    abstract public function defterBilgisi(): ?array;

    public static function bootDefterIzler(): void
    {
        static::creating(fn ($m) => DefterGecmisi::onceMuhurla($m->defterBilgisi()));
        static::created(fn ($m) => DefterGecmisi::kaydet('eklendi', $m->defterBilgisi()));
        static::updating(function ($m): void {
            if (! DefterGecmisi::acikMi()) {
                return;
            }
            $eski = (new static)->setRawAttributes($m->getOriginal(), true);
            $eski->exists = true;
            DefterGecmisi::guncelle($eski->defterBilgisi(), $m->defterBilgisi());
        });
        static::deleting(fn ($m) => DefterGecmisi::kaydet('silindi', $m->defterBilgisi()));
    }
}
