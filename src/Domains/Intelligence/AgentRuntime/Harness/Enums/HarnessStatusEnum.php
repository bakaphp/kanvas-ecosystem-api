<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;

enum HarnessStatusEnum: string
{
    case STARTING = 'starting';
    case RUNNING = 'running';
    case AWAITING_ANSWER = 'awaiting_answer';
    case AWAITING_PERMISSION = 'awaiting_permission';
    case IDLE = 'idle';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }

    /**
     * The terminal statuses as stored values, for a `whereIn`. Derived from {@see self::isTerminal()}
     * rather than listed again, so a new terminal case cannot be added to one and missed in the other.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(
            array_map(
                static fn (self $case): string => $case->value,
                array_filter(self::cases(), static fn (self $case): bool => $case->isTerminal()),
            )
        );
    }

    /**
     * A session parked on a question or a permission is still live work, so the Task must NOT move —
     * `TaskStatusEnum::BLOCKED` is terminal and every poller bails on it, which would strand the run
     * the moment somebody answers. The waiting state lives on the session row instead.
     */
    public function toTaskStatus(): TaskStatusEnum
    {
        return match ($this) {
            self::STARTING, self::RUNNING, self::IDLE,
            self::AWAITING_ANSWER, self::AWAITING_PERMISSION => TaskStatusEnum::IN_PROGRESS,
            self::COMPLETED => TaskStatusEnum::DONE,
            self::FAILED => TaskStatusEnum::BLOCKED,
            self::CANCELLED => TaskStatusEnum::SKIPPED,
        };
    }

    public function isWaitingOnAHuman(): bool
    {
        return in_array($this, [self::AWAITING_ANSWER, self::AWAITING_PERMISSION], true);
    }
}
