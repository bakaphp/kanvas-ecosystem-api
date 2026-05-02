<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Spatie\LaravelData\Data;

/**
 * Tenant-scoped ledger event payload, shared between AppendEventAction
 * (synchronous writer) and AppendToLedgerJob (queued wrapper).
 *
 * `apps_id` and `companies_id` are always required — the caller must
 * supply tenant scope explicitly so the ledger can be written from
 * non-HTTP contexts (jobs, console, observers) safely.
 *
 * `payload` is the input/context that triggered the event;
 * `result` and `error` are mutually exclusive outcome data:
 * - status=success → result set
 * - status=error   → error set
 * - status=info    → both null (pure observation events)
 */
class Event extends Data
{
    public function __construct(
        public readonly int $appsId,
        public readonly int $companiesId,
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
