<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Kanvas\AccessControlList\Templates\OwnerRoleTemplate;
use Kanvas\AccessControlList\Templates\AdminRoleTemplate;
use Kanvas\AccessControlList\Templates\UsersRoleTemplate;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Bouncer;
use Kanvas\Apps\Models\Apps;
use Kanvas\AccessControlList\Enums\RolesEnums;

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
                    Bouncer::allow($role)->to($value, $key);
                }
            }
        }
    }
}
