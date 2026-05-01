<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Spatie\LaravelData\Data;

/**
 * Tenant-scoped ledger event payload, shared between AppendEventAction
 * (synchronous writer) and AppendToLedgerJob (queued wrapper).
 *
 * Tenant scope (app, company) is required — the caller must supply both
 * explicitly so the ledger can be written from non-HTTP contexts (jobs,
 * console, observers) safely.
 *
 * `payload` is the input/context that triggered the event;
 * `result` and `error` are mutually exclusive outcome data:
 * - status=success → result set
 * - status=error   → error set
 * - status=info    → both null (pure observation events)
 *
 * `actor_*` and `source_entity_*` stay as type+id pairs (polymorphic —
 * no single typed model to pass for an actor or arbitrary source entity).
 */
class Event extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly ?CompanyInterface $company,
        public readonly string $sourceDomain,
        public readonly string $eventType,
        public readonly EventStatusEnum $status = EventStatusEnum::INFO,
        public readonly ?string $sourceEntityType = null,
        public readonly ?int $sourceEntityId = null,
        public readonly ?string $actorType = null,
        public readonly ?int $actorId = null,
        public readonly ?array $payload = null,
        public readonly ?array $result = null,
        public readonly ?array $error = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $correlationId = null,
        public readonly ?Carbon $occurredAt = null,
    ) {
    }
}
