<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\DataTransferObject;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Spatie\LaravelData\Data;

/**
 * Task creation/update payload. Always tied to a parent Plan, which carries
 * the tenant scope.
 *
 * NOTE: when used as a member of an "initial tasks" array passed to
 * CreatePlanAction (where the parent Plan doesn't exist yet), pass null
 * for $plan — CreatePlanAction wires the relationship after creating
 * the parent.
 */
class Task extends Data
{
    public function __construct(
        public readonly ?Plan $plan,
        public readonly string $title,
        public readonly int $sequence = 0,
        public readonly ?string $description = null,
        public readonly TaskStatusEnum $status = TaskStatusEnum::PENDING,
        public readonly ?array $result = null,
        public readonly ?string $blockedReason = null,
    ) {
    }
}
