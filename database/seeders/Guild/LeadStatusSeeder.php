<?php

namespace Database\Seeders\Guild;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeadStatusSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        DB::table('leads_status')->insert([
            [
                'name' => 'Active',
                'is_default' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Inactive',
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        DB::table('leads_participants_types')->insert([
            [
                'id' => 1,
                'name' => 'Participants',
                'apps_id' => 0,
                'companies_id' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}
