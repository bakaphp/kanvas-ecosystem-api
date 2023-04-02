<?php

namespace Database\Seeders;

use Baka\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Kanvas\Enums\SubscriptionTypeEnums;

class AppSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('apps')->insert([
            'name' => 'Kanvas',
            'key' => '059ddaaf-89b5-4158-a85a-90cbd69aa34b',
            'is_public' => 1,
            'description' => 'Kanvas Ecosystem',
            'created_at' => date('Y-m-d H:i:s'),
            //'default_apps_plan_id' => 1,
            //'subscription_types_id' => SubscriptionTypeEnums::GROUP->getValue(),
            'payments_active' => 1,
            'ecosystem_auth' => 1,
            'is_actived' => 1,
            'is_deleted' => 0
        ]);

        DB::table('apps_keys')->insert([
            'name' => 'Kanvas',
            'client_id' => Str::uuid(),
            'client_secret_id' => Str::random(60),
            'apps_id' => 1,
            'users_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0
        ]);
    }
}
