<?php

namespace Kanvas\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Jobs\OnBoardingJob;
use Kanvas\Users\Models\Users;
use Throwable;

class SetupService
{
    public function onBoarding(Users $user, Apps $app, ?CompanyInterface $company = null): void
    {
        try {
            OnBoardingJob::dispatch(
                $user,
                $company instanceof CompanyInterface ? $company->defaultBranch()->firstOrFail() : $user->getCurrentBranch(),
                $app
            );
        } catch (Throwable $e) {
            //no email sent
        }
    }
}
