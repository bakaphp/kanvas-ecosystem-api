<?php

namespace Kanvas\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Kanvas\Database\Seeders\CountriesSeeder;
use Kanvas\Database\Seeders\StatesSeeder;
use Kanvas\Database\Seeders\CitiesSeeder;
use Illuminate\Database\Seeder;

/**
 * Locations Seeder
 */
class LocationsSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(
            [
            CountriesSeeder::class,
            StatesSeeder::class,
            CitiesSeeder::class,
            ]
        );
    }
}
