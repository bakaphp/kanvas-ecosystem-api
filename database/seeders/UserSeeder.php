<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Kanvas\Utils\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->insert([
            'id' => -1,
            'user_activation_email' => Str::uuid(),
            'email' => 'anonymous@kanvas.dev',
            'password' => password_hash('bakatest123567', PASSWORD_DEFAULT),
            'firstname' => 'Anonymous',
            'lastname' => 'Anonymous',
            'default_company' => 1,
            'displayname' => 'anonymous',
            'system_modules_id' => 2,
            'default_company_branch' => 1,
            'user_last_login_try' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 1,
            'user_active' => 1,
            'is_deleted' => 0
        ]);
    }
}
