<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Exception;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\Database\Models;

class AssignRoleAction
{
    /**
     * __construct.
     */
    public function __construct(
        public Users|BaseModel $entity,
        public Role $role,
        public ?Apps $app = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * execute.
     */
    public function execute(): void
    {
        // we will only allow one role per user per app
        /*         $userRole = Models::query('assigned_roles')
                            ->where('entity_id', $this->entity->getId())
                            ->where('entity_type', Users::class)
                            ->where('scope', RolesEnums::getScope($this->app))
                            ->whereNot('role_id', $this->role->id);

                if ($userRole->count()) {
                    $userRole->delete();
                } */

        Bouncer::assign($this->role->name)->to($this->entity);

        try {
            if ($this->entity instanceof Users) {
                $this->entity->getAppProfile($this->app)->update([
                    'user_role' => $this->role->id,
                ]);
            }
        } catch (Exception $e) {
            //on signups this record might not exist yet , so we ignore it
            //the assign company will handle it, not great we will refactor in v2
        }
    }
}
