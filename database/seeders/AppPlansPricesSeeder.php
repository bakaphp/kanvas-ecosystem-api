<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppPlansPricesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('apps_plans_prices')->insert([
            [
                'apps_plans_id' => '1',
                'stripe_id' => 'price_1Q11XeBwyV21ueMMd6yZ4Tl5',
                'amount' => 59.00,
                'currency' => 'USD',
                'interval' => "year",
                'is_default' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'apps_plans_id' => '1',
                'stripe_id' => 'price_1Q1NGrBwyV21ueMMkJR2eA8U',
                'amount' => 5.00,
                'currency' => 'USD',
                'interval' => "monthly",
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'apps_plans_id' => '2',
                'stripe_id' => 'price_1Q11akBwyV21ueMMRweBmalF',
                'amount' => 199.00,
                'currency' => 'USD',
                'interval' => "year",
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'apps_plans_id' => '3',
                'stripe_id' => 'price_1Q11brBwyV21ueMMMBrwGdog',
                'amount' => 250.00,
                'currency' => 'USD',
                'interval' => "year",
                'is_default' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    }
}
