<?php

declare(strict_types=1);

namespace Database\Seeders\Souk;

use Illuminate\Database\Seeder;
use Kanvas\Souk\Discounts\Models\DiscountType;

class DiscountTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $discountTypes = [
            [
                'name' => 'Percentage',
                'description' => 'Percentage discount off the order total',
            ],
            [
                'name' => 'Fixed Amount',
                'description' => 'Fixed amount discount off the order total',
            ],
            [
                'name' => 'Free Shipping',
                'description' => 'Free shipping on the order',
            ],
            [
                'name' => 'Buy X Get Y',
                'description' => 'Buy a certain quantity and get another product free or discounted',
            ],
            [
                'name' => 'Tiered',
                'description' => 'Different discount rates based on order total thresholds',
            ],
            [
                'name' => 'Bundle',
                'description' => 'Discount when purchasing a specific bundle of products',
            ],
            [
                'name' => 'First Time Customer',
                'description' => 'Special discount for first-time customers',
            ],
            [
                'name' => 'Loyalty',
                'description' => 'Discount for loyal customers based on purchase history',
            ],
            [
                'name' => 'Seasonal',
                'description' => 'Time-limited seasonal promotions',
            ],
            [
                'name' => 'Referral',
                'description' => 'Discount for customers referred by existing customers',
            ],
        ];

        foreach ($discountTypes as $type) {
            DiscountType::firstOrCreate(
                ['name' => $type['name']],
                [
                    'description' => $type['description'],
                    'apps_id' => 0,
                ]
            );
        }

        $this->command->info('Discount types seeded successfully!');
    }
}
