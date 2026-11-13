<?php

namespace App\Models;

use App\Models\Concerns\HaneyeAit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Banka extends Model
{
    use HaneyeAit;

    protected $table = 'bankalar';

    protected $fillable = ['hane_id', 'ad', 'renk'];

    /**
     * @return HasMany<Kart, $this>
     */
    public function kartlar(): HasMany
    {
        return $this->hasMany(Kart::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return ['id' => $this->id, 'ad' => $this->ad, 'renk' => $this->renk];
    }
}
