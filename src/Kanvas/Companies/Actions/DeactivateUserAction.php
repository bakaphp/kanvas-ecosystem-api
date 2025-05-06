<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class DeactivateUserAction
{
    public function __construct(
        protected Users $user,
        protected Apps $app
    ) {
    }

    public function execute(): bool
    {
        $userAssociate = UsersRepository::belongsToThisApp($this->user, $this->app);
        AuthenticationService::logoutFromAllDevices($userAssociate->user, $this->app);
        return $userAssociate->deActive();
    }
}
