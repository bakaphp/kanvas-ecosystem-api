<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Enums\AppEnums;
use Kanvas\Apps\Models\Apps;
use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;

class ForbiddenRolesUsersAction
{
    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected Companies $company,
    ) {
    }

    public function execute(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->app, $this->company));
        $roles = $this->user->getRoles();
        foreach($roles as $role) {
            $this->user->retract($role->name);
        }

    }
}
