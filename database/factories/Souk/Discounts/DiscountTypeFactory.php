<?php

declare(strict_types=1);

namespace Database\Factories\Souk\Discounts;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Discounts\Models\DiscountType;

class DiscountTypeFactory extends Factory
{
    protected $model = DiscountType::class;

    public function definition(): array
    {
        $types = ['Percentage', 'Fixed Amount', 'Free Shipping', 'Buy X Get Y'];

        return [
            'apps_id' => Apps::factory(),
            'name' => $this->faker->randomElement($types),
            'description' => $this->faker->sentence(),
            'is_deleted' => false,
        ];
    }
}
