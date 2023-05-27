<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class UpdateRoleAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $title = null,
        public ?Apps $app = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * execute.
     */
    public function execute(?Companies $company = null): Role
    {
        $role = Role::find($this->id);

        if ($role->scope !== RolesEnums::getKey($this->app, $company)) {
            throw new AuthorizationException('You don\'t have permission to update this role');
        }

        $role->name = $this->name;
        $role->title = $this->title;
        $role->saveOrFail();

        return $role;
    }
}
