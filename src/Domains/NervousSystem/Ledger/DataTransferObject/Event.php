<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Spatie\LaravelData\Data;

/**
 * `result` / `error` are mutually exclusive by status:
 *   success → result set, error → error set, info → both null.
 *
 * `actor_*` and `source_entity_*` are type+id pairs (polymorphic — no
 * typed-model arg). `company` is nullable for catalog rows (Skill / Tool).
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
        public readonly int $payloadSchemaVersion = 1,
        public readonly ?array $result = null,
        public readonly ?array $error = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
        public readonly ?Carbon $occurredAt = null,
    ) {
    }
}
