<?php

namespace Database\Seeders;

use Database\Seeders\Social\MessageTypesSeeder;
use Illuminate\Database\Seeder;

class SocialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            MessageTypesSeeder::class,
        ]);
    }
}
