<?php

namespace Database\Factories;

use App\Enums\HesapTuru;
use App\Models\Hesap;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hesap>
 */
class HesapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ad' => fake()->unique()->words(2, true),
            'tur' => HesapTuru::AraciKurum,
            'sira' => 1,
        ];
    }
}
