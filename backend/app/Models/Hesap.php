<?php

namespace App\Models;

use App\Enums\HesapTuru;
use App\Models\Concerns\Arsivlenir;
use Database\Factories\HesapFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Varlıkların durduğu yer: "Yatırım hesabım", "Eşimin hesabı", "Banka kasası"… Banka / hesap numarası tutulmaz.
 */
class Hesap extends Model
{
    use Arsivlenir;

    protected string $arsivTuru = 'hesap';

    /** @return array<int, array<string, mixed>>|null */
    public function arsivAlt(): ?array
    {
        return $this->islemler()->with('varlik:id,kod')->get()->map(fn (Islem $i): array => ['tarih' => $i->tarih->toDateString(), 'islem' => $i->tur->value, 'varlik' => $i->varlik?->kod, 'miktar' => $i->miktar])->take(500)->values()->all() ?: null;
    }

    /** @use HasFactory<HesapFactory> */
    use HasFactory;

    protected $table = 'hesaplar';

    protected $fillable = ['user_id', 'ad', 'tur', 'renk', 'sira', 'not', 'zekata_dahil', 'zekat_notu'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tur' => HesapTuru::class, 'zekata_dahil' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Islem, $this>
     */
    public function islemler(): HasMany
    {
        return $this->hasMany(Islem::class);
    }

    /**
     * Kullanıcının görebildiği hesaplar: kendisinin ve hanesindeki kullanıcıların (`User::haneKullaniciIdleri`).
     *
     * @param  Builder<Hesap>  $sorgu
     */
    public function scopeErisilebilir(Builder $sorgu, User $user): void
    {
        $sorgu->whereIn('hesaplar.user_id', $user->haneKullaniciIdleri());
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            // Hanede başkasının hesabıysa arayüz sahibinin adını yazar
            'sahip_id' => $this->user_id,
            'sahip_ad' => $this->relationLoaded('user') ? $this->user?->ad : null,
            'sahip_resim' => $this->relationLoaded('user') ? $this->user?->resimAdresi() : null,
            'ad' => $this->ad,
            'tur' => $this->tur->value,
            'renk' => $this->renk,
            'sira' => $this->sira,
            'not' => $this->not,
            'zekata_dahil' => $this->zekata_dahil ?? true,
            'zekat_notu' => $this->zekat_notu,
        ];
    }
}
