<?php

declare(strict_types=1);

namespace Database\Seeders\Souk;

use Illuminate\Database\Seeder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Users\Models\Users;

class SampleDiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $app = Apps::first();
        $company = Companies::first();
        $user = Users::first();

        if (!$app || !$company || !$user) {
            $this->command->warn('Skipping sample discounts - no app, company, or user found');
            return;
        }

        $percentageType = DiscountType::where('name', 'Percentage')->first();
        $fixedAmountType = DiscountType::where('name', 'Fixed Amount')->first();
        $firstTimeType = DiscountType::where('name', 'First Time Customer')->first();
        $seasonalType = DiscountType::where('name', 'Seasonal')->first();

        $sampleDiscounts = [
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'Welcome 10% Off',
                'description' => 'Get 10% off your first order',
                'code' => 'WELCOME10',
                'value' => 10,
                'is_percentage' => true,
                'is_active' => true,
                'is_one_per_customer' => true,
                'min_order_value' => 50,
            ],
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'Summer Sale 20%',
                'description' => 'Summer sale - 20% off everything',
                'code' => 'SUMMER20',
                'value' => 20,
                'is_percentage' => true,
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addMonths(3),
                'max_discount_amount' => 100,
            ],
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $fixedAmountType->id,
                'name' => '$25 Off Orders Over $100',
                'description' => 'Get $25 off when you spend $100 or more',
                'code' => 'SAVE25',
                'value' => 25,
                'is_percentage' => false,
                'is_active' => true,
                'min_order_value' => 100,
            ],
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'VIP 15% Discount',
                'description' => 'Exclusive VIP customer discount',
                'code' => 'VIP15',
                'value' => 15,
                'is_percentage' => true,
                'is_active' => true,
                'usage_limit' => 100,
            ],
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'Flash Sale 30%',
                'description' => 'Limited time flash sale',
                'code' => 'FLASH30',
                'value' => 30,
                'is_percentage' => true,
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addDays(7),
                'usage_limit' => 50,
                'max_discount_amount' => 150,
            ],
            // Migration of old hardcoded discounts
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'PDLC 10% Off',
                'code' => 'pdlc10',
                'value' => 10,
                'is_percentage' => true,
                'is_active' => true,
            ],
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'PR 10% Off',
                'code' => 'pr10',
                'value' => 10,
                'is_percentage' => true,
                'is_active' => true,
            ],
            [
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'users_id' => $user->id,
                'discount_type_id' => $percentageType->id,
                'name' => 'Dev 100% Off',
                'description' => 'Development testing discount',
                'code' => 'dev100',
                'value' => 100,
                'is_percentage' => true,
                'is_active' => app()->environment('development'),
            ],
        ];

        foreach ($sampleDiscounts as $discountData) {
            Discount::firstOrCreate(
                [
                    'code' => $discountData['code'],
                    'apps_id' => $discountData['apps_id'],
                    'companies_id' => $discountData['companies_id'],
                ],
                $discountData
            );
        }

        $this->command->info('Sample discounts seeded successfully!');
    }
}
