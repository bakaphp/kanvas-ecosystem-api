<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Owner-only paths (admin invites, owner roles, system roles) need an owner, and granting Owner to the
 * shared test user would leak the role into every other test in the run: act as a fresh one instead.
 */
trait ActsAsAppOwner
{
    protected function actingAsAppOwner(): Users
    {
        $owner = $this->createUser();

        Bouncer::scope()->onceTo(
            RolesEnums::getScope(app(Apps::class)),
            fn () => $owner->assign(RolesEnums::OWNER->value)
        );
        Bouncer::refreshFor($owner);

        $this->actingAs($owner, 'api');

        return $owner;
    }
}
