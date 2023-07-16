<?php

namespace Database\Seeders\Guild;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Models\ContactType;

class ContactTypeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        DB::table('contacts_types')->insert([
            [
                'companies_id' => 0,
                'users_id' => 0,
                'name' => 'Email',
            ],[
                'companies_id' => 0,
                'users_id' => 0,
                'name' => 'Phone',
            ],
        ]);
    }
}
