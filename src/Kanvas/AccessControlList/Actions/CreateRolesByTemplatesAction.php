<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Templates\AdminRoleTemplate;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\AccessControlList\Templates\OwnerRoleTemplate;
use Kanvas\AccessControlList\Templates\UsersRoleTemplate;
use Kanvas\Apps\Models\Apps;
use Silber\Bouncer\Database\Role as SilberRole;

class CreateRolesByTemplatesAction
{
    protected array $templates = [
        OwnerRoleTemplate::class,
        AdminRoleTemplate::class,
        UsersRoleTemplate::class,
    ];

    public function __construct(
        protected Apps $app
    ) {
        Bouncer::scope()->to(RolesEnums::getScope($app));
    }

    public function execute(): void
    {
        foreach ($this->templates as $template) {
            $templateInstance = new $template();
            $role = $templateInstance->role;
            $denied = $templateInstance->denied;
            $allowed = $templateInstance->allowed;
            if (empty($allowed)) {
                $allowed = ModulesRepositories::getAllAbilities();
            }
            foreach ($allowed as $key => $permissions) {
                foreach ($permissions as $value) {
                    if (in_array($value, $denied)) {
                        continue;
                    }
                    $savedRole = SilberRole::where('name', $role)->first();
                    if ($savedRole) {
                        (new DisallowAllPermissionOnRoleAction($savedRole))->execute();
                    }
                    // Bouncer::allow($role)->to($value, $key);
                }
            }
        }
    }
}
