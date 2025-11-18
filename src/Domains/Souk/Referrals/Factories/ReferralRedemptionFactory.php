<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Referrals\Models\ReferralRedemption;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Referrals\Models\ReferralRedemption>
 */
class ReferralRedemptionFactory extends Factory
{
    protected $model = ReferralRedemption::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'apps_id' => function (): int {
                return app(Apps::class)->getId();
            },
            'companies_id' => 0,
            'referral_codes_id' => null,
            'referrer_user_id' => null,
            'referee_user_id' => null,
            'orders_id' => null,
            'discounts_id' => null,
            'referrer_points_awarded' => $this->faker->numberBetween(100, 1000),
            'referee_discount_amount' => $this->faker->randomFloat(2, 5, 50),
            'status' => 'pending',
            'redeemed_at' => null,
        ];
    }

    public function withAppId(int $appId): static
    {
        return $this->state(fn () => [
            'apps_id' => $appId,
        ]);
    }

    public function withCompanyId(int $companyId): static
    {
        return $this->state(fn () => [
            'companies_id' => $companyId,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'redeemed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'redeemed_at' => null,
        ]);
    }
}
