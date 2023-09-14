<?php

namespace Database\Seeders;

use Database\Seeders\Guild\ContactTypeSeeder;
use Database\Seeders\Guild\LeadStatusSeeder;
use Illuminate\Database\Seeder;

class GuildSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            ContactTypeSeeder::class,
            LeadStatusSeeder::class,
        ]);
    }
}
