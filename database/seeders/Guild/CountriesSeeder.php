<?php

namespace Database\Seeders\Guild;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactTypeSeeder extends Seeder
{
    
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        DB::connection('crm')->table('contacts_types')->insert(
            [
                [
                    'id' => 1,
                    'companies_id' => 0,
                    'users_id' => 0,
                    'name' => 'Email',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'id' => 2,
                    'companies_id' => 0,
                    'users_id' => 0,
                    'name' => 'Phone',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ]
        );
    }
}
