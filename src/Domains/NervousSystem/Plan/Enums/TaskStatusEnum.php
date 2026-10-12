<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

enum TaskStatusEnum: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    /**
     * Parked on a person or a date, with a booked wake (`wake_at`). Not terminal and not in flight: the
     * continuation loop returns WAIT instead of dispatching, bands do not advance past it, the heartbeat
     * leaves it alone until its wake is overdue. It leaves this state only through its wake or a reply
     * that answers it — never through `blocked`, which every poller treats as the end of the road.
     */
    case WAITING = 'waiting';
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
     * The completed statuses as raw string values — for `whereIn('status', ...)` filters.
     *
     * @return array<int, string>
     */
    public static function completedStatusValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::completedStatuses());
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
     * Statuses nothing advances out of on its own. Wider than completedStatuses() — BLOCKED is
     * terminal for any poller or runner (they stop looking at the task) without being completion, so
     * pollers must not park a task here for a condition they intend to recover from.
     *
     * @return array<int, self>
     */
    public static function terminalStatuses(): array
    {
        return [self::DONE, self::SKIPPED, self::BLOCKED];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminalStatuses(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function terminalStatusValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::terminalStatuses());
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
            'wait', 'snoozed', 'snooze', 'parked' => 'waiting',
            default => strtolower(trim($value)),
        };

        return self::from($canonical);
    }
}
