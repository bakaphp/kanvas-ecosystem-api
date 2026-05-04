<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

enum TaskStatusEnum: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    case DONE = 'done';
    case SKIPPED = 'skipped';

    /**
     * Statuses that count as "completed" for completion_pct calculation.
     * @return array<int, self>
     */
    public static function completedStatuses(): array
    {
        return [self::DONE, self::SKIPPED];
    }

    /**
     * Statuses that count as "in flight" — task is actively being worked.
     * @return array<int, self>
     */
    public static function inFlightStatuses(): array
    {
        return [self::IN_PROGRESS];
    }

    /**
     * Like ::from() but tolerates common synonyms ("completed" → "done",
     * "started" → "in_progress", etc.) and case/whitespace variations.
     * Agents and humans will naturally reach for these — accept them
     * rather than throw.
     */
    public static function fromAlias(string $value): self
    {
        $canonical = match (strtolower(trim($value))) {
            'completed', 'complete', 'finished', 'finish' => 'done',
            'in-progress', 'inprogress', 'started', 'start', 'running' => 'in_progress',
            'skip' => 'skipped',
            default => strtolower(trim($value)),
        };

        return self::from($canonical);
    }
}
