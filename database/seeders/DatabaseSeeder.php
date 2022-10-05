<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            CurrencySeeder::class,
            AppSeeder::class,
            AppSettingsSeeder::class,
            AppPlansSeeder::class,
            CountriesSeeder::class,
            StatesSeeder::class,
            //CitiesSeeder::class,
            RolesSeeder::class,
            SourceSeeder::class,
            SystemModuleSeeder::class,
            UserSeeder::class,
            NotificationTypesSeeder::class,
            TemplateSeeder::class,
        ]);
    }
}
