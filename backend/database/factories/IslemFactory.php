<?php

namespace Database\Factories;

use App\Enums\IslemTuru;
use App\Models\Hesap;
use App\Models\Islem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Islem>
 */
class IslemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hesap_id' => Hesap::factory(),
            'user_id' => fn (array $a): int => Hesap::find($a['hesap_id'])->user_id,
            'tur' => IslemTuru::Alis,
            'miktar' => 10,
            'birim_fiyat' => 100,
            'komisyon' => 0,
            'tarih' => now()->subDays(10)->toDateString(),
        ];
    }
}
