<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('companies_groups')->insert([
            'name' => 'Kanvas',
            'users_id' => 1,
            'apps_id' => 1,
            'is_default' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0
        ]);

        DB::table('companies')->insert([
            'name' => 'Kanvas',
            'users_id' => 1,
            'system_modules_id' => 1,
            'language' => 'EN',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0
        ]);

        DB::table('companies_associations')->insert([
            'name' => 'Kanvas',
            'users_id' => 1,
            'system_modules_id' => 1,
            'language' => 'EN',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0
        ]);
    }
}
