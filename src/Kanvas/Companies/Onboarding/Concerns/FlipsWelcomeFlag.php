<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;

/**
 * The legacy "finished onboarding" flag. users.welcome 0 → 1 is what UsersObserver watches to fire
 * AFTER_ONBOARDING, so the flip goes through the model save, once; the app profile copy is what
 * getAppWelcome() reads.
 */
trait FlipsWelcomeFlag
{
    protected function flipWelcome(Users $user, Apps $app): void
    {
        if (! (bool) $user->welcome) {
            $user->welcome = 1;
            $user->saveOrFail();
        }

        $profile = $user->getAppProfile($app);
        if (! (bool) $profile->welcome) {
            $profile->welcome = 1;
            $profile->saveOrFail();
        }
    }
}
