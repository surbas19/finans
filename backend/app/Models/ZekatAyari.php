<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use App\Services\Zekat\ZekatVarsayilanlari;
use Illuminate\Database\Eloquent\Model;

/**
 * Hanenin zekât kuralları (nisab ölçütü, oran, tür kuralları, borç, zekât günü). Satır ilk kayıtta yazılır;
 * kayıt yokken `haneIcin` varsayılanlı, kaydedilmemiş bir örnek verir.
 */
class ZekatAyari extends Model
{
    use HaneyeAit;

    protected $table = 'zekat_ayarlari';

    protected $fillable = [
        'hane_id', 'nisab_olcutu', 'nisab_altin_gram', 'nisab_gumus_gram', 'oran_binde', 'tur_kurallari', 'borc_dus',
        'borc_kaynaklari', 'yalniz_gunde_nisab', 'zekat_gunu_turu', 'hicri_ay', 'hicri_gun', 'miladi_ay', 'miladi_gun',
        'sonraki_zekat_gunu', 'hatirlatma_gun',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nisab_altin_gram' => 'float',
            'nisab_gumus_gram' => 'float',
            'oran_binde' => 'integer',
            'tur_kurallari' => 'array',
            'borc_dus' => 'boolean',
            'borc_kaynaklari' => 'array',
            'yalniz_gunde_nisab' => 'boolean',
            'sonraki_zekat_gunu' => 'date',
        ];
    }

    public static function haneIcin(int $haneId): self
    {
        return static::firstOrNew(['hane_id' => $haneId], ZekatVarsayilanlari::ayar());
    }

    /**
     * @return array{dahil: bool, yuzde: int}
     */
    public function turKurali(string $tur): array
    {
        $kural = ($this->tur_kurallari ?? [])[$tur] ?? null;

        return [
            'dahil' => (bool) ($kural['dahil'] ?? true),
            'yuzde' => (int) ($kural['yuzde'] ?? 100),
        ];
    }

    public function borcKaynagiAcik(string $kaynak): bool
    {
        return (bool) (($this->borc_kaynaklari ?? [])[$kaynak] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'nisab_olcutu' => $this->nisab_olcutu,
            'nisab_altin_gram' => $this->nisab_altin_gram,
            'nisab_gumus_gram' => $this->nisab_gumus_gram,
            'oran_binde' => $this->oran_binde,
            'tur_kurallari' => collect(ZekatVarsayilanlari::turKurallari())
                ->map(fn (array $v, string $tur): array => $this->turKurali($tur))->all(),
            'borc_dus' => $this->borc_dus,
            'borc_kaynaklari' => [
                'kart' => $this->borcKaynagiAcik('kart'),
                'fatura' => $this->borcKaynagiAcik('fatura'),
                'elle' => $this->borcKaynagiAcik('elle'),
            ],
            'yalniz_gunde_nisab' => $this->yalniz_gunde_nisab,
            'zekat_gunu_turu' => $this->zekat_gunu_turu,
            'hicri_ay' => $this->hicri_ay,
            'hicri_gun' => $this->hicri_gun,
            'miladi_ay' => $this->miladi_ay,
            'miladi_gun' => $this->miladi_gun,
            'sonraki_zekat_gunu' => $this->sonraki_zekat_gunu?->toDateString(),
            'hatirlatma_gun' => $this->hatirlatma_gun,
        ];
    }
}
