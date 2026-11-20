<?php

namespace App\Models;

use App\Models\Concerns\Arsivlenir;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Borç / alacak defteri kaydı. `yon`: borc (ben borçluyum) | alacak (bana borçlu). Kısmi ödemeler `BorcOdemesi`.
 */
class Borc extends Model
{
    use Arsivlenir;

    protected string $arsivTuru = 'borc';

    public function arsivBaslik(): string
    {
        return $this->kisi.' · '.($this->yon === self::BORC ? 'borç' : 'alacak');
    }

    /** @return array<int, array<string, mixed>>|null */
    public function arsivAlt(): ?array
    {
        return $this->odemeler->map(fn (BorcOdemesi $o): array => ['tarih' => $o->tarih->toDateString(), 'tutar' => (float) $o->tutar, 'not' => $o->not])->take(500)->values()->all() ?: null;
    }

    use HaneyeAit;

    public const BORC = 'borc';

    public const ALACAK = 'alacak';

    protected $table = 'borclar';

    protected $fillable = ['hane_id', 'yon', 'kisi', 'tutar', 'tarih', 'vade', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tutar' => 'decimal:2', 'tarih' => 'date', 'vade' => 'date'];
    }

    /**
     * @return HasMany<BorcOdemesi, $this>
     */
    public function odemeler(): HasMany
    {
        return $this->hasMany(BorcOdemesi::class)->orderByDesc('tarih')->orderByDesc('id');
    }

    public function odenenKurus(): int
    {
        return $this->odemeler->reduce(fn (int $t, BorcOdemesi $o): int => $t + Kurus::kurus($o->tutar), 0);
    }

    public function kalanKurus(): int
    {
        return max(0, Kurus::kurus($this->tutar) - $this->odenenKurus());
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        $kalan = $this->kalanKurus();

        return [
            'id' => $this->id,
            'yon' => $this->yon,
            'kisi' => $this->kisi,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar)),
            'odenen' => Kurus::tl($this->odenenKurus()),
            'kalan' => Kurus::tl($kalan),
            'durum' => $kalan === 0 ? 'kapali' : 'acik',
            'tarih' => $this->tarih->toDateString(),
            'vade' => $this->vade?->toDateString(),
            'vade_gecti' => $kalan > 0 && $this->vade !== null && $this->vade->isBefore(today()),
            'not' => $this->not,
            'odemeler' => $this->odemeler->map(fn (BorcOdemesi $o): array => $o->ozet())->values(),
        ];
    }
}
