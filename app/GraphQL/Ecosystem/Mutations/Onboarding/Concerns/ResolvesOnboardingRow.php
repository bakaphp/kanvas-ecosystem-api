<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Onboarding\Concerns;

use App\GraphQL\Concerns\ActingContext;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;

/**
 * Every onboarding mutation acts on the current company's row and answers with its state. The
 * permission is the one updateCompany uses: any member of the company, or the app owner.
 */
trait ResolvesOnboardingRow
{
    private function row(ActingContext $ctx): CompanyOnboarding
    {
        /** @var Companies $company */
        $company = $ctx->company;
        $company->hasCompanyPermission($ctx->user, $ctx->app);

        return new OnboardingStateService()->forCompany($ctx->app, $company, $ctx->user);
    }

    private function state(CompanyOnboarding $row): OnboardingState
    {
        return new OnboardingStateService()->state($row);
    }
}
