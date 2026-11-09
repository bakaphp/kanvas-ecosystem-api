<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Users\Models\Users;

/**
 * Work a section needs once its agents exist (`config.setup`). Runs as the last items of a
 * provisioning run, with per-item progress like an agent card, and must be idempotent: a second run,
 * or a run after the company already set the area up another way, changes nothing that exists.
 */
interface OnboardingSetupHookInterface
{
    /**
     * @return string|null why the setup is incomplete, or null when everything it owns is in place
     */
    public function run(Apps $app, Companies $company, Users $user): ?string;

    /**
     * Closes the onboarding setup plan's tasks whose requirement is now met; returns how many it closed.
     */
    public function syncSetupPlan(CompanyOnboarding $row): int;
}
