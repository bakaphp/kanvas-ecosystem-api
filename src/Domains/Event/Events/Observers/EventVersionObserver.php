<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Workflow\Enums\WorkflowEnum;

class EventVersionObserver
{
    public function created(EventVersion $eventVersion): void
    {
        $this->syncEventCounters($eventVersion);

        $eventVersion->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                'app' => $eventVersion->app,
            ]
        );
    }

    public function updated(EventVersion $eventVersion): void
    {
        if ($eventVersion->wasChanged(['start_at', 'end_at'])) {
            $this->syncEventCounters($eventVersion);
        }

        $eventVersion->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $eventVersion->app,
            ]
        );
    }

    public function deleted(EventVersion $eventVersion): void
    {
        $this->syncEventCounters($eventVersion);
    }

    /**
     * Keep the parent Event's versions_count, start_at, and end_at in sync
     * with its EventVersions. start_at = earliest version; end_at = latest version.
     */
    protected function syncEventCounters(EventVersion $eventVersion): void
    {
        /** @var Event|null $event */
        $event = $eventVersion->event;
        if ($event === null) {
            return;
        }

        $aggregates = DB::connection('event')
            ->table('event_versions')
            ->where('event_id', $event->getId())
            ->where('is_deleted', 0)
            ->selectRaw('COUNT(*) as cnt, MIN(start_at) as min_start, MAX(end_at) as max_end')
            ->first();

        $event->versions_count = $aggregates !== null ? (int) $aggregates->cnt : 0;

        if ($aggregates !== null && $aggregates->min_start !== null) {
            $event->start_at = $aggregates->min_start;
        }

        if ($aggregates !== null && $aggregates->max_end !== null) {
            $event->end_at = $aggregates->max_end;
        }

        $event->saveQuietly();
    }
}
