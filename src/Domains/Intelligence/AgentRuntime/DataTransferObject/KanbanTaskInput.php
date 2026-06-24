<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Provider-agnostic input for creating a kanban task on a runtime board.
 *
 * `idempotencyKey` should be the Kanvas entity uuid — the runtime dedups on it (re-create
 * returns the existing id), which is how a Kanvas push matches back on ingest. `parentIds`
 * empty = a root task (→ Kanvas Plan); non-empty = a child (→ Kanvas Task under that plan).
 */
final class KanbanTaskInput extends Data
{
    /**
     * @param list<string> $parentIds
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $assignee = null,
        public readonly ?string $body = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $parentIds = [],
        public readonly ?int $priority = null,
        public readonly ?string $tenant = null,
        public readonly ?string $workspace = null,
    ) {
    }
}
