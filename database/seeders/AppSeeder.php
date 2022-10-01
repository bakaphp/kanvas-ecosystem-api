<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        DB::table('apps')->insert(  [
            'name' => 'Kanvas',
            'key' => 'ac53fedf-f873-4b96-973a-2368690652b5',
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
    }
}
