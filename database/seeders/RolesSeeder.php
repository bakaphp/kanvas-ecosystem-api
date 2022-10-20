<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('roles_kanvas')->insert(
            [
                'name' => 'Admins',
                'description' => 'System Administrator',
                'scope' => 0,
                'companies_id' => 1,
                'apps_id' => 1,
                'is_default' => 1,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'name' => 'Users',
                'description' => 'Normal Users can (CRUD)',
                'scope' => 0,
                'companies_id' => 1,
                'apps_id' => 1,
                'is_default' => 1,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'name' => 'Agents',
                'description' => 'Agents Users can (CRU)',
                'scope' => 0,
                'companies_id' => 1,
                'apps_id' => 1,
                'is_default' => 1,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ]
        );
    }
}
