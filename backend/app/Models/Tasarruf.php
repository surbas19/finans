<?php

namespace App\Models;

use App\Models\Concerns\Arsivlenir;
use App\Models\Concerns\DefterIzler;
use App\Models\Concerns\HaneyeAit;
use App\Support\Kurus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bütçeden ayrılan tasarruf: portföy hesabına yazılan alış işleminin bütçedeki dönem bağı. Gider sayılmaz.
 */
class Tasarruf extends Model
{
    use Arsivlenir;
    use DefterIzler;

    protected string $arsivTuru = 'tasarruf';

    public function arsivBaslik(): string
    {
        return 'Tasarruf · '.($this->varlik?->kod ?? 'varlık').' · '.$this->tarih?->format('d.m.Y');
    }

    /** @return array<int, array<string, mixed>>|null */
    public function arsivAlt(): ?array
    {
        $islem = $this->islem;

        return $islem ? [['tur' => 'portfoy_islemi', 'tarih' => $islem->tarih->toDateString(), 'islem' => $islem->tur->value, 'miktar' => $islem->miktar]] : null;
    }

    use HaneyeAit;

    protected $table = 'tasarruflar';

    protected $fillable = ['hane_id', 'uye_id', 'hesap_id', 'varlik_id', 'miktar', 'islem_id', 'gun_id', 'tarih', 'donem', 'tutar_tl', 'not', 'olusturan_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tarih' => 'date', 'tutar_tl' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Islem, $this>
     */
    public function islem(): BelongsTo
    {
        return $this->belongsTo(Islem::class);
    }

    /**
     * İşlemsiz (başkasına verilen) kalemlerde ne alındığı.
     *
     * @return BelongsTo<Varlik, $this>
     */
    public function varlik(): BelongsTo
    {
        return $this->belongsTo(Varlik::class);
    }

    /**
     * @return BelongsTo<TasarrufGunu, $this>
     */
    public function gun(): BelongsTo
    {
        return $this->belongsTo(TasarrufGunu::class, 'gun_id');
    }

    /**
     * @return BelongsTo<Hesap, $this>
     */
    public function hesap(): BelongsTo
    {
        return $this->belongsTo(Hesap::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        // Portföy işlemi varsa ondan, yoksa (başkasına verilen alım) kendi alanlarından
        $varlik = $this->islem?->varlik ?? $this->varlik;

        return [
            'id' => $this->id,
            'no' => 'T-'.$this->id,
            'uye_id' => $this->uye_id,
            'hesap_id' => $this->hesap_id,
            'hesap_ad' => $this->hesap?->ad,
            'islem_id' => $this->islem_id,
            'islem_no' => $this->islem_id ? 'İ-'.$this->islem_id : null,
            'islem_dahil' => $this->islem ? ($this->islem->dahil ?? true) : null,
            'gun_id' => $this->gun_id,
            'gun_ad' => $this->gun?->ad,
            // Verilen alım: kime verildiği notta; hiçbir hesaba yazılmadı, yalnız aylık tasarruf toplamında
            'hesaba_eklendi' => $this->islem_id !== null,
            'varlik_id' => $varlik?->id,
            'varlik_ad' => $varlik?->ad,
            'varlik_kod' => $varlik?->kod,
            'varlik_tur' => $varlik?->tur->value,
            'varlik_birim' => $varlik?->birim,
            'miktar' => $this->islem?->miktar ?? ($this->miktar !== null ? (float) $this->miktar : null),
            // Düzeltme formu için: alışın fiyatı ve komisyonu (verilen alımda yalnız miktar / tutar bilinir)
            'birim_fiyat' => $this->islem?->birim_fiyat,
            'komisyon' => $this->islem?->komisyon,
            'tarih' => $this->tarih->toDateString(),
            'islem_zamani' => $this->created_at?->toIso8601String(),
            'donem' => $this->donem,
            'tutar' => Kurus::tl(Kurus::kurus($this->tutar_tl)),
            'not' => $this->not,
        ];
    }

    /** @return array<string, mixed>|null Defter'in sonradan değişiklik kaydı için bilgi */
    public function defterBilgisi(): ?array
    {
        return $this->hane_id === null || $this->donem === null ? null : [
            'hane_id' => (int) $this->hane_id, 'donem' => $this->donem, 'tur' => 'tasarruf', 'kayit_id' => $this->getKey(),
            'baslik' => 'Tasarruf T-'.$this->getKey(), 'tarih' => $this->tarih?->toDateString(), 'tutar' => $this->tutar_tl, 'not' => null,
        ];
    }
}
