<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Spatie\LaravelData\Data;

// Shape reserved for v1.5. v1 reads null; the action rejects goal_based at runtime.
class GoalBasedConfig extends Data
{
    public function __construct(
        /** @var array<int, array<string, mixed>> */
        public readonly array $goalSignals = [],
        public readonly ?int $maxWaitMinutes = null,
        public readonly ?string $onMaxWait = null,
    ) {
    }
}
