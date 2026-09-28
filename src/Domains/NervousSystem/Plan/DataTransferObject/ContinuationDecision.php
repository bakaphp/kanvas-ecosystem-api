<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\DataTransferObject;

use Kanvas\NervousSystem\Plan\Enums\ContinuationDecisionEnum;

/**
 * The verdict plus the counts it was derived from, so a ledger event records not just what was
 * decided but why — which is what makes a misbehaving loop diagnosable after the fact rather than
 * only while it is running.
 */
final readonly class ContinuationDecision
{
    public function __construct(
        public ContinuationDecisionEnum $verdict,
        public string $reason,
        public int $openTasks = 0,
        public int $blockedTasks = 0,
        public int $doneTasks = 0,
        public int $wakeCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toLedgerPayload(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
            'open_tasks' => $this->openTasks,
            'blocked_tasks' => $this->blockedTasks,
            'done_tasks' => $this->doneTasks,
            'wake_count' => $this->wakeCount,
        ];
    }
}
