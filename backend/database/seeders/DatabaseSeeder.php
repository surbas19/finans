<?php

namespace Database\Seeders;

use App\Fiyat\Katalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Katalog::esitle();

        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
