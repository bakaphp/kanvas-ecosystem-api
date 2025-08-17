<?php

declare(strict_types=1);

namespace Database\Factories\Souk\Discounts;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;

class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        $isPercentage = $this->faker->boolean();
        $value = $isPercentage
            ? $this->faker->randomFloat(2, 5, 50) // 5% to 50%
            : $this->faker->randomFloat(2, 5, 100); // $5 to $100

        return [
            'apps_id' => Apps::factory(),
            'companies_id' => Companies::factory(),
            'name' => $this->faker->catchPhrase() . ' Sale',
            'description' => $this->faker->sentence(),
            'discount_type_id' => DiscountType::factory(),
            'value' => $value,
            'is_percentage' => $isPercentage,
            'min_order_value' => $this->faker->optional(0.5)->randomFloat(2, 20, 200),
            'max_discount_amount' => $isPercentage ? $this->faker->optional(0.3)->randomFloat(2, 10, 100) : null,
            'code' => strtoupper($this->faker->unique()->lexify('????') . $this->faker->numerify('##')),
            'start_date' => $this->faker->optional(0.7)->dateTimeBetween('-1 month', '+1 month'),
            'end_date' => $this->faker->optional(0.7)->dateTimeBetween('+1 month', '+6 months'),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
            'usage_limit' => $this->faker->optional(0.6)->numberBetween(10, 1000),
            'usage_count' => 0,
            'is_one_per_customer' => $this->faker->boolean(30), // 30% chance of one per customer
            'is_deleted' => false,
        ];
    }

    public function percentage(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'is_percentage' => true,
                'value' => $this->faker->randomFloat(2, 5, 50),
            ];
        });
    }

    public function fixedAmount(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'is_percentage' => false,
                'value' => $this->faker->randomFloat(2, 5, 100),
            ];
        });
    }

    public function active(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => true,
                'start_date' => now()->subDay(),
                'end_date' => now()->addMonth(),
            ];
        });
    }

    public function expired(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'start_date' => now()->subMonth(),
                'end_date' => now()->subDay(),
            ];
        });
    }

    public function withCode(string $code): self
    {
        return $this->state(function (array $attributes) use ($code) {
            return [
                'code' => $code,
            ];
        });
    }

    public function unlimited(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'usage_limit' => null,
            ];
        });
    }
}
