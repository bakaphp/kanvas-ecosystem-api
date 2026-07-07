<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Claims\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Claims\Models\EntityClaim;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * Take an exclusive claim on an entity so no other agent acts on it concurrently.
 * Returns the claim on success, or null when a live claim is already held by a
 * different agent — the caller defers and retries rather than acting.
 *
 * Concurrency rests entirely on the unique index: two racing inserts both target
 * the same (apps_id, companies_id, entity_namespace, entity_id); one wins, the
 * other hits a duplicate-entry violation and we translate it to null.
 */
class AcquireEntityClaimAction
{
    private const int MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(
        private readonly Model $entity,
        private readonly Agent $agent,
        private readonly int $ttlSeconds = 90,
        private readonly ?string $reason = null,
        private readonly ?string $correlationId = null,
    ) {
    }

    public function execute(): ?EntityClaim
    {
        $appsId = $this->agent->apps_id;
        $companiesId = $this->agent->companies_id;
        $namespace = $this->entity::class;
        $entityId = (int) $this->entity->getKey();

        // A stale (expired) holder must not block a fresh acquire forever.
        EntityClaim::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('entity_namespace', $namespace)
            ->where('entity_id', $entityId)
            ->where('expires_at', '<', Carbon::now())
            ->delete();

        try {
            $claim = new EntityClaim();
            $claim->apps_id = $appsId;
            $claim->companies_id = $companiesId;
            $claim->entity_namespace = $namespace;
            $claim->entity_id = $entityId;
            $claim->agent_id = (int) $this->agent->getId();
            $claim->reason = $this->reason;
            $claim->correlation_id = $this->correlationId;
            $claim->expires_at = Carbon::now()->addSeconds($this->ttlSeconds);
            $claim->saveOrFail();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_ENTRY) {
                return $this->onContention(
                    $appsId,
                    $companiesId,
                    $namespace,
                    $entityId,
                );
            }

            throw $exception;
        }

        $this->emitClaimEvent('claim.acquired', $claim);

        return $claim;
    }

    /**
     * The unique slot is taken. If this same agent already holds it, renew and
     * treat as success (re-entrant acquire). If another agent holds it, defer.
     */
    private function onContention(
        int $appsId,
        int $companiesId,
        string $namespace,
        int $entityId,
    ): ?EntityClaim {
        $existing = EntityClaim::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('entity_namespace', $namespace)
            ->where('entity_id', $entityId)
            ->first();

        if ($existing !== null && $existing->agent_id === (int) $this->agent->getId()) {
            $existing->expires_at = Carbon::now()->addSeconds($this->ttlSeconds);
            $existing->saveOrFail();

            return $existing;
        }

        return null;
    }

    private function emitClaimEvent(string $eventType, EntityClaim $claim): void
    {
        new AppendEventAction(
            new EventData(
                app: $this->agent->app,
                company: $this->agent->company,
                sourceDomain: 'NervousSystem',
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                sourceEntityType: $claim->entity_namespace,
                sourceEntityId: $claim->entity_id,
                actorType: 'Agent',
                actorId: (int) $this->agent->getId(),
                payload: [
                    'claim_uuid' => $claim->uuid,
                    'reason' => $claim->reason,
                    'expires_at' => $claim->expires_at->toIso8601String(),
                ],
                correlationId: $claim->correlation_id,
            ),
        )->execute();
    }
}
