<?php

namespace Database\Seeders;

use Database\Seeders\Guild\ContactTypeSeeder;
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
        ]);
    }
}
