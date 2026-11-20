<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Aile\SilinenArsivi;
use Carbon\CarbonInterface;

/**
 * Silinince arşive yazılan model (`SilinenArsivi`). Model `$arsivTuru` verir; başlık, tutar, yapan, hane ve bağlı kayıt
 * özeti varsayılan olarak alanlardan çıkarılır, gerekirse model geçersiz kılar. Bağlı kayıtlar (DB cascade) `deleting`
 * anında yakalanır, çünkü cascade model olayı üretmez.
 */
trait Arsivlenir
{
    /** @var array<int, array<string, mixed>>|null */
    public ?array $arsivAltGecici = null;

    public static function bootArsivlenir(): void
    {
        static::deleting(function ($kayit): void {
            $kayit->arsivAltGecici = $kayit->arsivAlt();
        });
        static::deleted(fn ($kayit) => app(SilinenArsivi::class)->yaz($kayit));
    }

    public function arsivTuru(): string
    {
        return $this->arsivTuru;
    }

    public function arsivlenecekMi(): bool
    {
        return true;
    }

    /** @return array<int, string> arşive kopyalanmayan (büyük / ham) alanlar */
    public function arsivHaric(): array
    {
        return [];
    }

    public function arsivNeden(): ?string
    {
        return null;
    }

    public function arsivBaslik(): string
    {
        $ad = $this->getAttribute('aciklama') ?? $this->getAttribute('ad') ?? $this->getAttribute('kisi') ?? '#'.$this->getKey();
        $tarih = $this->getAttribute('islem_tarihi') ?? $this->getAttribute('tarih');
        $tarih = $tarih instanceof CarbonInterface ? $tarih->format('d.m.Y') : null;

        return trim($ad.($tarih ? ' · '.$tarih : ''));
    }

    public function arsivTutar(): ?string
    {
        $tutar = $this->getAttribute('tutar') ?? $this->getAttribute('tutar_tl');

        return $tutar === null ? null : (string) $tutar;
    }

    public function arsivOlusturanId(): ?int
    {
        $id = $this->getAttribute('olusturan_id') ?? $this->getAttribute('user_id');

        return $id === null ? null : (int) $id;
    }

    public function arsivHaneId(): ?int
    {
        $hane = $this->getAttribute('hane_id');
        if ($hane !== null) {
            return (int) $hane;
        }
        $kullanici = $this->getAttribute('user_id') ?? $this->arsivOlusturanId();

        return $kullanici === null ? null : (User::whereKey($kullanici)->value('hane_id') ?: null);
    }

    /** @return array<int, array<string, mixed>>|null bağlı kayıtların özeti (en çok 500) */
    public function arsivAlt(): ?array
    {
        return null;
    }
}
