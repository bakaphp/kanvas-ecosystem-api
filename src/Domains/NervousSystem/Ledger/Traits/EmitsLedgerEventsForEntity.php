<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Traits;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Provides `emitLedgerEvent()` on a model. Used by domain actions to log
 * lifecycle events with one line instead of 15.
 *
 * Differences from `EmitsNervousSystemEvents`:
 * - This trait is for **deliberate, action-driven** emission ("the agent
 *   approved this plan", "this task transitioned to done"). Actions call it
 *   explicitly with a chosen event_type and payload.
 * - `EmitsNervousSystemEvents` is for **automatic Eloquent-lifecycle**
 *   emission (created/updated/deleted observed by Eloquent events).
 *
 * The two coexist on the same model — the lifecycle trait fires `created`
 * via observer; actions fire domain events like `plan.approved` via this trait.
 *
 * Per-model overrides:
 *   - resolveDefaultActorType() — string actor type (User/Agent/System)
 *   - resolveDefaultActorId()   — int|null actor id
 *   - sourceDomainForLedger()   — overrides the source_domain (defaults
 *                                  to "NervousSystem")
 *
 * Models that use this trait must have `app()` and `company()` relations
 * (provided by KanvasModelTrait). For models without a company (e.g. Skill,
 * Tool catalog rows), `$this->company` returns null and the event is
 * stored with `companies_id=0`.
 */
trait EmitsLedgerEventsForEntity
{
    public function emitLedgerEvent(
        string $eventType,
        EventStatusEnum $status = EventStatusEnum::INFO,
        ?array $payload = null,
        ?array $result = null,
        ?array $error = null,
        ?int $durationMs = null,
        ?string $correlationId = null,
        ?string $actorType = null,
        ?int $actorId = null,
    ): Event {
        return new AppendEventAction(
            new EventData(
                app: $this->app,
                company: $this->company ?? null,
                sourceDomain: $this->sourceDomainForLedger(),
                eventType: $eventType,
                status: $status,
                sourceEntityType: static::class,
                sourceEntityId: (int) $this->getKey(),
                actorType: $actorType ?? $this->resolveDefaultActorType(),
                actorId: $actorId ?? $this->resolveDefaultActorId(),
                payload: $payload,
                result: $result,
                error: $error,
                durationMs: $durationMs,
                correlationId: $correlationId,
                occurredAt: Carbon::now(),
            ),
        )->execute();
    }

    protected function sourceDomainForLedger(): string
    {
        return 'NervousSystem';
    }

    protected function resolveDefaultActorType(): string
    {
        if (! empty($this->users_id ?? null)) {
            return 'User';
        }
        if (! empty($this->agent_id ?? null)) {
            return 'Agent';
        }

        return 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->users_id ?? $this->agent_id ?? null;
    }
}
