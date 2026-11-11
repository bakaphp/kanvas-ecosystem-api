<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

/**
 * The steps between "row created" and "prompt sent", in the order the launch job runs them.
 */
enum LaunchStageEnum: string
{
    case RESOLVING_REPOSITORY = 'resolving_repository';
    case STARTING_CONTAINER = 'starting_container';
    case PREPARING_WORKTREE = 'preparing_worktree';
    case PROMPTING = 'prompting';

    public function label(): string
    {
        return match ($this) {
            self::RESOLVING_REPOSITORY => 'resolving the repository',
            self::STARTING_CONTAINER => 'starting the runtime',
            self::PREPARING_WORKTREE => 'preparing the worktree',
            self::PROMPTING => 'sending the prompt',
        };
    }

    /**
     * How long the sweeper leaves a launch alone in this stage before calling it stalled.
     *
     * A first mirror clone runs with a 900-second allowance and touches nothing while git works, so the
     * worktree stage gets that budget plus a minute; everything else is seconds and keeps the default.
     */
    public function staleMinutes(int $default): int
    {
        return match ($this) {
            self::PREPARING_WORKTREE => max($default, 16),
            default => $default,
        };
    }
}
