<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Traits;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * For deliberate, action-driven emission (e.g. `plan.approved`). Coexists
 * with `EmitsNervousSystemEvents`, which auto-emits on Eloquent lifecycle —
 * both can be on the same model.
 *
 * For catalog rows without a company (Skill, Tool), `$this->company` is
 * null and the event lands with `companies_id=0`.
 */
trait EmitsLedgerEventsForEntity
{
    public function emitLedgerEvent(
        string $eventType,
        EventStatusEnum $status = EventStatusEnum::INFO,
        ?array $payload = null,
        int $payloadSchemaVersion = 1,
        ?array $result = null,
        ?array $error = null,
        ?int $durationMs = null,
        ?string $correlationId = null,
        ?string $causationId = null,
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
                payloadSchemaVersion: $payloadSchemaVersion,
                result: $result,
                error: $error,
                durationMs: $durationMs,
                correlationId: $correlationId,
                causationId: $causationId,
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
