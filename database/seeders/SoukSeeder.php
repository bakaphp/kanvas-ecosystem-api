<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Souk\DiscountTypeSeeder;
use Database\Seeders\Souk\SampleDiscountSeeder;
use Illuminate\Database\Seeder;

class SoukSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            DiscountTypeSeeder::class,
        ]);

        // Only seed sample discounts in development/testing environments
        if (app()->environment(['local', 'development', 'testing'])) {
            $this->call([
                SampleDiscountSeeder::class,
            ]);
        }
    }
}
