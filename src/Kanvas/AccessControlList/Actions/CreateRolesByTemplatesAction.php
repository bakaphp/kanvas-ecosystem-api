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
        $grants = [];
        $pairs = [];

        foreach ($this->templates as $template) {
            $templateInstance = new $template();

            if ($templateInstance->hasAllPermissions) {
                Bouncer::allow($templateInstance->role)->everything();

                continue;
            }

            $allowed = empty($templateInstance->allowed)
                ? ModulesRepositories::getAllAbilities()
                : $templateInstance->allowed;

            foreach ($allowed as $entityType => $abilities) {
                foreach ($abilities as $ability) {
                    if (in_array($ability, $templateInstance->denied)) {
                        continue;
                    }

                    $pairs[] = [$ability, $entityType];
                    $grants[$templateInstance->role][] = ResolveAbilitiesAction::key($ability, $entityType);
                }
            }
        }

        if (empty($grants)) {
            return;
        }

        $resolved = new ResolveAbilitiesAction()->execute($pairs);

        // One grant per role, not one per ability: Bouncer skips its own per-ability lookup when it
        // is handed Ability models, so each role costs a single lookup plus a single attach.
        foreach ($grants as $role => $keys) {
            Bouncer::allow($role)->to(
                array_values($resolved->only(array_unique($keys))->all())
            );
        }
    }
}
