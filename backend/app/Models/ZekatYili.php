<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir zekât gününün değişmez anlık görüntüsü: o günkü ayar, kalemler, borçlar, nisab ve zekât tutarı kopyalanır
 * (sonradan fiyat ya da hesap değişse de geçmiş bozulmaz). `kesinlesti` olunca salt okunurdur.
 */
class ZekatYili extends Model
{
    use HaneyeAit;

    public const TASLAK = 'taslak';

    public const KESINLESTI = 'kesinlesti';

    protected $table = 'zekat_yillari';

    protected $fillable = [
        'hane_id', 'zekat_gunu', 'durum', 'ayar_kopyasi', 'brut', 'borc', 'net', 'nisab', 'nisab_ustu', 'zekat', 'usd_kuru',
        'nisab_birim_fiyat', 'eksik_veri', 'eksikler', 'hesaplandi_at', 'kesinlesti_at', 'not', 'olusturan_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'zekat_gunu' => 'date',
            'ayar_kopyasi' => 'array',
            'brut' => 'decimal:2',
            'borc' => 'decimal:2',
            'net' => 'decimal:2',
            'nisab' => 'decimal:2',
            'nisab_ustu' => 'boolean',
            'zekat' => 'decimal:2',
            'usd_kuru' => 'float',
            'nisab_birim_fiyat' => 'float',
            'eksik_veri' => 'boolean',
            'eksikler' => 'array',
            'hesaplandi_at' => 'datetime',
            'kesinlesti_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ZekatYiliKalemi, $this>
     */
    public function kalemler(): HasMany
    {
        return $this->hasMany(ZekatYiliKalemi::class)->orderByDesc('hesaba_giren')->orderBy('id');
    }

    /**
     * @return HasMany<ZekatYiliBorcu, $this>
     */
    public function borclar(): HasMany
    {
        return $this->hasMany(ZekatYiliBorcu::class)->orderBy('id');
    }

    /**
     * @return HasMany<ZekatOdemesi, $this>
     */
    public function odemeler(): HasMany
    {
        return $this->hasMany(ZekatOdemesi::class)->orderByDesc('tarih')->orderByDesc('id');
    }

    public function kesinlesmisMi(): bool
    {
        return $this->durum === self::KESINLESTI;
    }

    /** Verilen zekât (kuruş) */
    public function odenenKurus(): int
    {
        return $this->odemeler->reduce(fn (int $t, ZekatOdemesi $o): int => $t + Kurus::kurus($o->tutar), 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(bool $detay = false): array
    {
        $this->loadMissing('odemeler');
        $zekat = Kurus::kurus($this->zekat);
        $odenen = $this->odenenKurus();

        return [
            'id' => $this->id,
            'zekat_gunu' => $this->zekat_gunu->toDateString(),
            'durum' => $this->durum,
            'brut' => Kurus::tl(Kurus::kurus($this->brut)),
            'borc' => Kurus::tl(Kurus::kurus($this->borc)),
            'net' => Kurus::tl(Kurus::kurus($this->net)),
            'nisab' => Kurus::tl(Kurus::kurus($this->nisab)),
            'nisab_birim_fiyat' => $this->nisab_birim_fiyat,
            'nisab_ustu' => $this->nisab_ustu,
            'zekat' => Kurus::tl($zekat),
            'odenen' => Kurus::tl($odenen),
            'kalan' => Kurus::tl(max(0, $zekat - $odenen)),
            'usd_kuru' => $this->usd_kuru,
            'eksik_veri' => $this->eksik_veri,
            'hesaplandi_at' => $this->hesaplandi_at->toIso8601String(),
            'kesinlesti_at' => $this->kesinlesti_at?->toIso8601String(),
            'not' => $this->not,
            ...($detay ? [
                'ayar' => $this->ayar_kopyasi,
                'eksikler' => $this->eksikler ?? [],
                'kalemler' => $this->kalemler->map(fn (ZekatYiliKalemi $k): array => $k->ozet())->values(),
                'borclar' => $this->borclar->map(fn (ZekatYiliBorcu $b): array => $b->ozet())->values(),
                'odemeler' => $this->odemeler->map(fn (ZekatOdemesi $o): array => $o->ozet())->values(),
            ] : []),
        ];
    }
}
