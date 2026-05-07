<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Users\Models\UserRoles;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class UserRoleHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $userRoleQuery = UserRoles::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($userRoleQuery, $whereConditions, $boolean);
        }

        $builder->whereHas('roles', function ($query) use ($userRoleQuery) {
            $query->mergeConstraintsFrom($userRoleQuery);
        });
    }
}
