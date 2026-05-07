<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Loyalty\Models\LoyaltyOffer;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Loyalty\Models\LoyaltyOffer>
 */
class LoyaltyOfferFactory extends Factory
{
    protected $model = LoyaltyOffer::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'loyalty_programs_id' => LoyaltyProgram::factory(),
            'apps_id' => function (): int {
                return app(Apps::class)->getId();
            },
            'companies_id' => 0,
            'name' => $this->faker->word(),
            'headline' => $this->faker->sentence(),
            'description' => $this->faker->text(),
            'offer_type' => $this->faker->randomElement(['discount', 'points', 'exclusive', 'free_shipping']),
            'trigger_type' => $this->faker->randomElement([
                'first_purchase',
                'tier_upgrade',
                'birthday',
                'milestone',
                'referral',
                'seasonal',
                'manual',
            ]),
            'trigger_value' => null,
            'reward_value' => $this->faker->numberBetween(50, 500),
            'expiration_hours' => $this->faker->numberBetween(24, 720),
            'status' => 'active',
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

    public function discount(): static
    {
        return $this->state(fn (array $attributes) => [
            'offer_type' => 'discount',
            'reward_value' => $this->faker->numberBetween(5, 50),
        ]);
    }

    public function points(): static
    {
        return $this->state(fn (array $attributes) => [
            'offer_type' => 'points',
            'reward_value' => $this->faker->numberBetween(100, 1000),
        ]);
    }

    public function freeShipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'offer_type' => 'free_shipping',
            'reward_value' => null,
        ]);
    }

    public function birthdayTrigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => 'birthday',
            'name' => 'Birthday Special',
        ]);
    }

    public function tierUpgradeTrigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => 'tier_upgrade',
            'name' => 'Tier Upgrade Bonus',
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
        ]);
    }
}
