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
        DB::table('roles')->insert(
            [
                'name' => 'Admins',
                'title' => 'System Administrator',
                'scope' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'name' => 'Users',
                'title' => 'Normal Users can (CRUD)',
                'scope' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'name' => 'Agents',
                'title' => 'Agents Users can (CRU)',
                'scope' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
