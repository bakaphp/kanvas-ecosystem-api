<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Actions;
use Silber\Bouncer\Database\Role as SilberRole;
use Kanvas\AccessControlList\Models\Role;
use Bouncer;
class DisallowAllPermissionOnRoleAction {

    public function __construct(
        protected SilberRole $role
    ) {
    }

    public function execute(): void
    {
        $abilities = $this->role->abilities;
        foreach ($abilities as $ability) {
            Bouncer::disallow($this->role->name)->to($ability->name, $ability->entity_type);
        }
    }
}