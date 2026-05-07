<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Loyalty\Models\LoyaltyTier;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Loyalty\Models\LoyaltyTier>
 */
class LoyaltyTierFactory extends Factory
{
    protected $model = LoyaltyTier::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'loyalty_programs_id' => LoyaltyProgram::factory(),
            'companies_id' => $this->faker->numberBetween(1, 10),
            'name' => $this->faker->word(),
            'level' => $this->faker->numberBetween(1, 5),
            'min_points' => $this->faker->numberBetween(0, 5000),
            'earning_multiplier' => $this->faker->randomFloat(2, 1.0, 2.0),
            'benefits' => [
                'free_shipping' => true,
                'bonus_points' => 50,
                'early_access' => false,
            ],
        ];
    }

    public function bronze(): static
    {
        return $this->state(fn () => [
            'name' => 'Bronze',
            'level' => 1,
            'min_points' => 0,
            'earning_multiplier' => 1.0,
        ]);
    }

    public function silver(): static
    {
        return $this->state(fn () => [
            'name' => 'Silver',
            'level' => 2,
            'min_points' => 1000,
            'earning_multiplier' => 1.25,
        ]);
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'name' => 'Gold',
            'level' => 3,
            'min_points' => 5000,
            'earning_multiplier' => 1.5,
        ]);
    }

    public function platinum(): static
    {
        return $this->state(fn () => [
            'name' => 'Platinum',
            'level' => 4,
            'min_points' => 10000,
            'earning_multiplier' => 2.0,
        ]);
    }
}
