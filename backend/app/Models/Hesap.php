<?php

namespace App\Models;

use App\Enums\HesapTuru;
use Database\Factories\HesapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Varlıkların durduğu yer: "Yatırım hesabım", "Eşimin hesabı", "Banka kasası"… Banka / hesap numarası tutulmaz.
 */
class Hesap extends Model
{
    /** @use HasFactory<HesapFactory> */
    use HasFactory;

    protected $table = 'hesaplar';

    protected $fillable = ['user_id', 'ad', 'tur', 'renk', 'sira', 'not'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tur' => HesapTuru::class];
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
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'tur' => $this->tur->value,
            'renk' => $this->renk,
            'sira' => $this->sira,
            'not' => $this->not,
        ];
    }
}
