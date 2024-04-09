<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Roles;

use Kanvas\AccessControlList\DataTransferObject\RoleType as RoleTypeDto;
use Kanvas\AccessControlList\Models\RoleType;
use Kanvas\Apps\Models\Apps;

class RolesTypesManagementMutation
{
    public function create(mixed $root, array $request): RoleType
    {
        $dto = RoleTypeDto::from([
            'app' => app(Apps::class),
            'name' => $request['input']['name'],
            'description' => $request['input']['description'] ?? null,
        ]);

        return RoleType::create([
            'apps_id' => $dto->app->getId(),
            'name' => $dto->name,
            'description' => $dto->description,
        ]);
    }

    public function update(mixed $root, array $request): RoleType
    {
        $roleType = RoleType::findOrFail($request['id']);
        $dto = RoleTypeDto::from([
            'app' => app(Apps::class),
            'name' => $request['input']['name'],
            'description' => $request['input']['description'] ?? $roleType->description,
        ]);

        $roleType->update([
            'apps_id' => $dto->app->getId(),
            'name' => $dto->name,
            'description' => $dto->description,
        ]);

        return $roleType;
    }
}
