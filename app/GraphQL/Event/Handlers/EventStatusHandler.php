<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Events\Models\EventStatus;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class EventStatusHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $statusQuery = EventStatus::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($statusQuery, $whereConditions, $boolean);
        }

        $builder->whereHas('eventStatus', function ($query) use ($statusQuery) {
            $query->mergeConstraintsFrom($statusQuery);
        });
    }
}
