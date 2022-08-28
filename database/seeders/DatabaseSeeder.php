<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Kanvas\Apps\Apps\Factories\AppsFactory;
use Kanvas\Apps\Apps\Models\Apps as ModelsApps;
use Kanvas\Apps\Models\Apps;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        //\Kanvas\Apps\Apps\Models\Apps::factory(1)->create();
        ModelsApps::factory()->count(1)->create();
        \Kanvas\SystemModules\Models\SystemModules::factory(1)->create();
        \Kanvas\Roles\Models\Roles::factory(1)->create();
    }
}
