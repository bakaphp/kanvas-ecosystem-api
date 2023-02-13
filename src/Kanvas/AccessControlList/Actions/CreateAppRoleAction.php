<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Kanvas\Apps\Models\AppRoles;
use Kanvas\Apps\Models\Apps;

class CreateAppRoleAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public Apps $app,
        public string $name
    ) {
    }

    /**
     * execute.
     *
     * @return AppRoles
     */
    public function execute(): AppRoles
    {
        $appRoles = new AppRoles();
        $appRoles->apps_id = $this->app->getKey();
        $appRoles->roles_name = $this->name;
        $appRoles->saveOrFail();

        return $appRoles;
    }
}
