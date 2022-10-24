<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Actions;

use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Exception;

class UpdateRole
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $title = null,
    ) {
    }

    /**
     * execute
     *
     * @return Role
     */
    public function execute(): Role
    {
        $role = Role::find($this->id);
        if ($role->scope != RolesRepository::getScope()) {
            throw new Exception('You dont have permission to update this role');
        }
        $role->name = $this->name;
        $role->title = $this->title;
        $role->saveOrFail();
        return $role;
    }
}
