<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Illuminate\Support\Facades\Validator;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;

class UpdateRoleAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $id,
        public ?string $name = null,
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
        if ($this->name) {
            $validator = Validator::make(
                [
                    'name' => $this->name,
                ],
                [
                    'name' => 'required|unique:roles,name,' . $this->id . ',id,scope,' . RolesEnums::getScope($this->app),
                ]
            );

            if ($validator->fails()) {
                throw new ValidationException($validator->errors()->first() . 'for roles in the current app');
            }
        }

        $role = Role::find($this->id);
        $role->update([
            'name' => $this->name ?? $role->name,
            'title' => $this->title ?? ($role->title ?? $role->name),
        ]);

        return $role;
    }
}
