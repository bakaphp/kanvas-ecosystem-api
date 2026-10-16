<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;

/**
 * SIPGO keeps live versions under soft-deleted events; Kanvas cannot — `EventVersion.event` is
 * non-null in GraphQL and the global `is_deleted = 0` scope hides the parent, so every query that
 * reaches such a version fails with an InvariantViolation (KANVAS-ECOSYSTEM-5GS). A live version
 * keeps its parent live.
 */
class RestoreDeletedParentEventsAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
    ) {
    }

    public function execute(): int
    {
        $liveVersionParents = EventVersion::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->select('event_id');

        $restored = 0;

        // withTrashed()->where(), not onlyTrashed(): Baka's scope flags with 0/1, and Laravel's
        // onlyTrashed() checks `whereNotNull`, which matches every row.
        Event::withTrashed()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_deleted', 1)
            ->whereIn('id', $liveVersionParents)
            ->chunkById(200, function ($events) use (&$restored): void {
                foreach ($events as $event) {
                    $event->restore();
                    $restored++;
                }
            });

        return $restored;
    }
}
