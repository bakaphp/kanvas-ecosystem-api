<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\SystemModules\Models\SystemModules;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class ResourceTypeHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        if (! isset($whereConditions['value'])) {
            return;
        }

        try {
            $resourceClass = SystemModules::getSystemModuleNameSpaceBySlug($whereConditions['value']);

            $morphClass = (new $resourceClass())->getMorphClass();

            $whereConditions['value'] = $morphClass;

            $this->operator->applyConditions($builder, $whereConditions, $boolean);
        } catch (\InvalidArgumentException $e) {
            $this->operator->applyConditions($builder, $whereConditions, $boolean);
        }
    }
}
