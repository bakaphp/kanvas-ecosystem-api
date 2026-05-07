<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Loyalty\Models\LoyaltyProgram>
 */
class LoyaltyProgramFactory extends Factory
{
    protected $model = LoyaltyProgram::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'apps_id' => function (): int {
                return app(Apps::class)->getId();
            },
            'companies_id' => 0,
            'name' => $this->faker->word() . ' Rewards',
            'description' => $this->faker->sentence(),
            'points_per_dollar' => $this->faker->randomFloat(2, 0.5, 2.0),
            'earn_multiplier' => $this->faker->randomFloat(2, 1.0, 3.0),
            'expiration_days' => $this->faker->numberBetween(90, 730),
            'is_active' => true,
            'referral_enabled' => false,
            'referral_strategy' => 'single',
            'referral_config' => null,
            'meta' => null,
        ];
    }

    public function withAppId(int $appId): static
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function withCompanyId(int $companyId): static
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }

    public function withReferral(): static
    {
        return $this->state(fn (array $attributes) => [
            'referral_enabled' => true,
            'referral_config' => [
                'referrer_bonus' => 1000,
                'referee_bonus' => 500,
                'max_referrals' => null,
            ],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
