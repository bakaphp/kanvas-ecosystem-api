<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Enums;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;

enum JobStatusEnum: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * pi.dev omits `status` on some accepted-but-not-yet-queued responses; that is a queued job.
     *
     * @param array<string, mixed> $response
     */
    public static function fromApiResponse(array $response): self
    {
        return isset($response['status'])
            ? self::from((string) $response['status'])
            : self::QUEUED;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            self::QUEUED, self::RUNNING => false,
        };
    }

    /**
     * Coarse mapping onto the Task lifecycle. Task has no first-class failed/cancelled — a failed
     * job becomes a BLOCKED task (carries blocked_reason), a cancelled job becomes SKIPPED.
     */
    public function toTaskStatus(): TaskStatusEnum
    {
        return match ($this) {
            self::QUEUED => TaskStatusEnum::PENDING,
            self::RUNNING => TaskStatusEnum::IN_PROGRESS,
            self::COMPLETED => TaskStatusEnum::DONE,
            self::FAILED => TaskStatusEnum::BLOCKED,
            self::CANCELLED => TaskStatusEnum::SKIPPED,
        };
    }
}
