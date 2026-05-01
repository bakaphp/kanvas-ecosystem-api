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

    /**
     * Build a Task DTO from a GraphQL/HTTP input array. The plan reference
     * is optional here because tasks attached to a *new* plan via
     * CreatePlanAction don't have a parent yet — the action wires it after
     * creating the parent plan.
     */
    public static function fromMultiple(?Plan $plan, array $data, int $defaultSequence = 0): self
    {
        return new self(
            plan: $plan,
            title: (string) $data['title'],
            sequence: isset($data['sequence']) ? (int) $data['sequence'] : $defaultSequence,
            description: $data['description'] ?? null,
            status: isset($data['status'])
                ? TaskStatusEnum::from((string) $data['status'])
                : TaskStatusEnum::PENDING,
            result: $data['result'] ?? null,
            blockedReason: $data['blocked_reason'] ?? null,
        );
    }

    /**
     * Map an array of task-input rows into TaskData instances.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, self>
     */
    public static function fromMultipleArray(?Plan $plan, array $rows): array
    {
        $tasks = [];
        foreach ($rows as $sequence => $row) {
            $tasks[] = self::fromMultiple($plan, $row, defaultSequence: $sequence);
        }

        return $tasks;
    }
}
