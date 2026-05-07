<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Events\Models\EventStatus;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class EventVersionStatusHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $eventStatusQuery = EventStatus::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($eventStatusQuery, $whereConditions, $boolean);
        }

        // Join through the event relationship to filter by event status
        $builder->whereHas('event', function ($query) use ($eventStatusQuery) {
            $query->whereHas('eventStatus', function ($statusQuery) use ($eventStatusQuery) {
                $statusQuery->mergeConstraintsFrom($eventStatusQuery);
            });
        });
    }
}
