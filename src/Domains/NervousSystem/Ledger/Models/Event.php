<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Enums\EventCategoryEnum;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Models\ImmutableBaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $source_domain
 * @property string|null $source_entity_type
 * @property int|null $source_entity_id
 * @property string $event_type
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $status
 * @property array|null $payload
 * @property int $payload_schema_version
 * @property array|null $result
 * @property array|null $error
 * @property int|null $duration_ms
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string|null $category
 * @property Carbon $occurred_at
 * @property Carbon $indexed_at
 */
class Event extends ImmutableBaseModel
{
    use UuidTrait;

    protected $table = 'nervous_system_events';

    protected $guarded = [];

    #[Override]
    protected static function booted(): void
    {
        // Materialize the Pulse category on insert so the daily rollup
        // can GROUP BY category without recomputing it row-by-row.
        // Deterministic: always recompute (idempotent for the same
        // event_type+status pair). Sets the raw attribute directly to
        // bypass the accessor — the accessor falls back to compute
        // when the column is null, which would mask the write here.
        static::creating(function (self $event): void {
            $event->setAttribute('category', self::categoryFor(
                (string) $event->event_type,
                (string) $event->status,
            )?->value);
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'source_entity_id' => 'integer',
            'actor_id' => 'integer',
            'duration_ms' => 'integer',
            'payload' => Json::class,
            'payload_schema_version' => 'integer',
            'result' => Json::class,
            'error' => Json::class,
            'occurred_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at');
    }

    public function scopeWithCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }

    public function scopeCausedBy(Builder $query, string $causationId): Builder
    {
        return $query->where('causation_id', $causationId);
    }

    /**
     * Pulse-stream category for this event. Stored on the row at insert
     * time via the `creating` hook above. For legacy rows where the
     * column is null (pre-backfill), falls back to computing on the fly
     * so reads stay graceful while the backfill is in progress.
     */
    public function getCategoryAttribute(?string $value): ?string
    {
        if ($value !== null) {
            return $value;
        }

        return self::categoryFor((string) $this->event_type, (string) $this->status)?->value;
    }

    /**
     * Pure mapping function — kept static + parameterized so it's also
     * usable from tests, the subscription filter, and any future
     * backfill that wants to materialize category as a real column.
     */
    public static function categoryFor(string $eventType, string $status): ?EventCategoryEnum
    {
        // Error status always routes to WARNING regardless of event_type.
        if ($status === EventStatusEnum::ERROR->value) {
            return EventCategoryEnum::WARNING;
        }

        $type = strtolower($eventType);

        // Internal/observability events that should NOT appear in the
        // user-facing Pulse stream. Returning null filters them at the
        // subscription source.
        $internalPrefixes = [
            'dashboard.metrics_rolled_up',
            'dashboard.metrics_rollup_completed',
            'plan.agent.wake_dispatched',
        ];
        foreach ($internalPrefixes as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return null;
            }
        }

        // Failure-suffixed event types route to WARNING even when status
        // is INFO (some emitters set status=INFO but the event itself is
        // a failure record).
        if (
            str_ends_with($type, '.failed')
            || str_ends_with($type, '.failure')
            || str_ends_with($type, '.invocation_failed')
            || str_ends_with($type, '.error')
        ) {
            return EventCategoryEnum::WARNING;
        }

        // Decisions: planning, approvals, routing.
        if (
            str_starts_with($type, 'plan.created')
            || str_starts_with($type, 'plan.approved')
            || str_starts_with($type, 'plan.rejected')
            || str_starts_with($type, 'plan.updated')
            || str_starts_with($type, 'plan.task.created')
            || str_starts_with($type, 'agent.decided')
            || str_starts_with($type, 'agent.routed')
            || str_starts_with($type, 'skill.granted')
            || str_starts_with($type, 'skill.revoked')
        ) {
            return EventCategoryEnum::DECIDE;
        }

        // Acts: things actually executed.
        if (
            str_starts_with($type, 'integration.')
            || str_starts_with($type, 'plan.task.completed')
            || str_starts_with($type, 'plan.task.started')
            || str_starts_with($type, 'plan.swarm_milestone_posted')
            || str_starts_with($type, 'plan.agent.invoked')
            || str_starts_with($type, 'plan.agent.replied')
            || str_ends_with($type, '.executed')
            || str_ends_with($type, '.completed')
            || str_ends_with($type, '.posted')
            || str_ends_with($type, '.sent')
        ) {
            return EventCategoryEnum::ACT;
        }

        // Understanding: context assembly, classification, scoring.
        if (
            str_contains($type, 'context.')
            || str_contains($type, '.assembled')
            || str_contains($type, '.understood')
            || str_contains($type, '.classified')
            || str_contains($type, '.scored')
        ) {
            return EventCategoryEnum::UNDERSTAND;
        }

        // Signals: incoming externals — leads, messages, webhooks, etc.
        if (
            str_starts_with($type, 'signal.')
            || str_starts_with($type, 'workflow.entry')
            || str_starts_with($type, 'lead.')
            || str_starts_with($type, 'message.received')
            || str_starts_with($type, 'webhook.')
        ) {
            return EventCategoryEnum::SIGNAL;
        }

        // Anything that doesn't map cleanly is internal — null. Refine
        // the rules above when a new event type needs a category.
        return null;
    }
}
