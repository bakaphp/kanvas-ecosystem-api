<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Users\Models\Users;

class DeactivateUserAction
{
    public function __construct(
        protected Users $user,
        protected Apps $app
    ) {
    }

    public function execute(): bool
    {
        $profile = $this->user->getAppProfile($this->app);
        AuthenticationService::logoutFromAllDevices($this->user, $this->app);

        return $profile->deActive();
    }
}
