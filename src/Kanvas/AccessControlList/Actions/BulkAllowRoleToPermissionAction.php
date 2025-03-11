<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Silber\Bouncer\Database\Role as SilberRole;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class BulkAllowRoleToPermissionAction
{
    public function __construct(
        protected Apps $app,
        protected SilberRole $role,
        protected array $permissions
    ) {
    }

    public function execute(): void
    {
        foreach ($this->permissions as $permission) {
            $modelName = $permission['model_name'];
            $systemModule = SystemModulesRepository::getByModelName($modelName, $this->app);
            foreach ($permission['permission'] as $perm) {
                $ability = $systemModule->abilities()->where('name', $perm)
                            ->firstOrFail();
                Bouncer::allow($this->role->name)->to($perm, $modelName);
            }
        }
    }
}
