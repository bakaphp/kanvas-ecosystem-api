<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Loyalty\Models\LoyaltyTierMembership;
use Kanvas\Users\Models\Users;

class AssignLoyaltyProgramAction
{
    public function __construct(
        private Users $user,
        private AppInterface $app
    ) {
    }

    public function execute(): ?LoyaltyTierMembership
    {
        // Find eligible programs for this user
        $eligiblePrograms = $this->findEligiblePrograms();

        if ($eligiblePrograms->isEmpty()) {
            return null;
        }

        // Get the highest priority program
        $selectedProgram = $eligiblePrograms->first();

        if (! $selectedProgram) {
            return null;
        }

        // Check if user already has membership
        $existingMembership = LoyaltyTierMembership::fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->where('loyalty_programs_id', $selectedProgram->getId())
            ->first();

        if ($existingMembership) {
            return $existingMembership;
        }

        // Get the base tier for this program
        $baseTier = $selectedProgram->tiers()->where('level', 1)->first();

        if (! $baseTier) {
            return null;
        }

        $companyId = 0;

        try {
            $companyId = $this->user->getCurrentCompany()->getId();
        } catch (Exception $e) {
            // User has no current company, default to 0
        }

        // Create membership
        return LoyaltyTierMembership::create([
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'companies_id' => $companyId,
            'loyalty_tiers_id' => $baseTier->getId(),
            'loyalty_programs_id' => $selectedProgram->getId(),
            'lifetime_points' => 0,
            'current_points' => 0,
        ]);
    }

    /**
     * Find programs user is eligible for.
     */
    private function findEligiblePrograms(): Collection
    {
        return LoyaltyProgram::where('apps_id', $this->app->getId())
            ->where('is_active', true)
            ->whereHas('eligibilityRules', function (Builder $query) {
                $query->where('is_active', true)
                    ->where('auto_enroll', true)
                    ->orderBy('priority', 'desc');
            })
            ->get();
    }
}
