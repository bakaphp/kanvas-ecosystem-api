<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\DataTransferObject;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Spatie\LaravelData\Data;

/**
 * `$plan` is nullable so initial tasks can be staged for a not-yet-created
 * Plan inside CreatePlanAction (which wires the relationship post-save).
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
