<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Users\Models\Users;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Referrals\Models\ReferralCode>
 */
class ReferralCodeFactory extends Factory
{
    protected $model = ReferralCode::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'apps_id' => function (): int {
                return app(Apps::class)->getId();
            },
            'companies_id' => 0,
            'users_id' => function (): int {
                return app(Users::class)->getId();
            },
            'code' => $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'loyalty_programs_id' => LoyaltyProgram::factory(),
            'discounts_id' => null,
            'referrer_reward' => 500,
            'referee_reward' => 100,
            'referee_discount' => 10.0,
            'max_uses' => null,
            'current_uses' => 0,
            'expires_at' => $this->faker->optional(0.7)->dateTimeBetween('+1 day', '+1 year'),
            'is_active' => true,
            'strategy' => 'single',
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

    public function withUserId(int $userId): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'users_id' => $userId,
            ];
        });
    }

    public function withMaxUses(int $max): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => $max,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function multipleUses(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => 'multiple',
            'max_uses' => $this->faker->numberBetween(10, 100),
        ]);
    }

    public function almostMaxed(int $maxUses = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => $maxUses,
            'current_uses' => $maxUses - 1,
        ]);
    }
}
