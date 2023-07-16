<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\Social\Messages\Models\UserMessageActivityType;

class MessageActivityTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        UserMessageActivityType::create([
            'apps_id' => 1,
            'name' => 'follow',
        ]);
    }
}
