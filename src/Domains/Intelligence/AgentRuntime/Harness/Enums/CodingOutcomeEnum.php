<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;

/**
 * How a coding job ended, from the point of view of whoever asked for it. A run is not over when the
 * agent stops talking; it is over when the work is somewhere a person can use it, or it is known
 * that it never will be.
 */
enum CodingOutcomeEnum: string
{
    case PUSHED = 'pushed';
    case PUSH_FAILED = 'push_failed';
    /** Finished with changes but no branch of its own to push — the diff is reviewed on the workspace. */
    case REVIEW_ONLY = 'review_only';
    /** Finished; a human has been asked before the push. Not final. */
    case AWAITING_PUSH = 'awaiting_push';
    case PUSH_REJECTED = 'push_rejected';
    case PUSH_EXPIRED = 'push_expired';
    case PUSH_CANCELLED = 'push_cancelled';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return $this !== self::AWAITING_PUSH;
    }

    /**
     * A push that failed, was refused or timed out blocks the task rather than completing it: the
     * work exists, but a person has to act before anyone can use it, and "done" would hide that.
     */
    public function taskStatus(): TaskStatusEnum
    {
        return match ($this) {
            self::AWAITING_PUSH => TaskStatusEnum::WAITING,
            self::PUSHED, self::REVIEW_ONLY => TaskStatusEnum::DONE,
            self::CANCELLED, self::PUSH_CANCELLED => TaskStatusEnum::SKIPPED,
            self::PUSH_FAILED, self::PUSH_REJECTED, self::PUSH_EXPIRED, self::FAILED => TaskStatusEnum::BLOCKED,
        };
    }

    public function planStatus(): PlanStatusEnum
    {
        return match ($this->taskStatus()) {
            TaskStatusEnum::DONE => PlanStatusEnum::DONE,
            TaskStatusEnum::SKIPPED => PlanStatusEnum::CANCELLED,
            default => PlanStatusEnum::FAILED,
        };
    }

    public function headline(): string
    {
        return match ($this) {
            self::PUSHED, self::REVIEW_ONLY => '✅ Coding job finished',
            self::AWAITING_PUSH => '✅ Coding job finished — awaiting push approval',
            self::PUSH_FAILED => '⚠️ Coding job finished but the push failed',
            self::PUSH_REJECTED => '🛑 Push rejected',
            self::PUSH_EXPIRED => '⚠️ Push approval expired',
            self::PUSH_CANCELLED => '🛑 Push approval withdrawn',
            self::CANCELLED => '🛑 Coding job stopped',
            self::FAILED => '⚠️ Coding job failed',
        };
    }
}
