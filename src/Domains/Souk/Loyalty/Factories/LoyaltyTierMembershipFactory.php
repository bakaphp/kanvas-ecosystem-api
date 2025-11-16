<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Loyalty\Models\LoyaltyTier;
use Kanvas\Souk\Loyalty\Models\LoyaltyTierMembership;
use Kanvas\Users\Models\Users;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Loyalty\Models\LoyaltyTierMembership>
 */
class LoyaltyTierMembershipFactory extends Factory
{
    protected $model = LoyaltyTierMembership::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'apps_id' => function (): int {
                return app(Apps::class)->getId();
            },
            'companies_id' => 0,
            'users_id' => Users::factory(),
            'loyalty_tiers_id' => LoyaltyTier::factory(),
            'loyalty_programs_id' => LoyaltyProgram::factory(),
            'lifetime_points' => $this->faker->numberBetween(0, 10000),
            'current_points' => $this->faker->numberBetween(0, 5000),
            'tier_promoted_at' => $this->faker->optional(0.7)->dateTime(),
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

    public function withUserId(int $userId): static
    {
        return $this->state(fn () => [
            'users_id' => $userId,
        ]);
    }

    public function withTierId(int $tierId): static
    {
        return $this->state(fn () => [
            'loyalty_tiers_id' => $tierId,
        ]);
    }

    public function withProgramId(int $programId): static
    {
        return $this->state(fn () => [
            'loyalty_programs_id' => $programId,
        ]);
    }
}
