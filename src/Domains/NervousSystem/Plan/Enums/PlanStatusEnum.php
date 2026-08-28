<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

enum PlanStatusEnum: string
{
    /**
     * The brief is still being agreed. A plan here is deliberately NOT executable: it exists so an
     * unanswered request leaves a visible row that can be chased, rather than evaporating with the
     * conversation that started it.
     */
    case INTAKE = 'intake';

    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case AWAITING_APPROVAL = 'awaiting_approval';
    case DONE = 'done';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Statuses that count as "open" — agent is still pursuing the plan.
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::INTAKE, self::DRAFT, self::ACTIVE, self::BLOCKED, self::AWAITING_APPROVAL];
    }

    /**
     * Statuses where no work may be dispatched, whatever the tasks say. Intake has no agreed goal
     * yet and approval has not been given — dispatching against either would be acting on something
     * nobody signed off.
     *
     * @return array<int, self>
     */
    public static function nonExecutableStatuses(): array
    {
        return [self::INTAKE, self::AWAITING_APPROVAL];
    }

    public function isExecutable(): bool
    {
        return ! in_array($this, self::nonExecutableStatuses(), true);
    }

    /**
     * Statuses that count as "terminal" — the plan won't change further.
     * @return array<int, self>
     */
    public static function terminalStatuses(): array
    {
        return [self::DONE, self::FAILED, self::CANCELLED];
    }

    /**
     * Like ::from() but tolerates common synonyms ("completed" → "done",
     * "canceled" → "cancelled", "started" → "active", etc.) and case
     * variations. Agents and humans naturally reach for these — accept
     * them rather than throw.
     */
    public static function fromAlias(string $value): self
    {
        $canonical = match (strtolower(trim($value))) {
            'completed', 'complete', 'finished', 'finish' => 'done',
            'canceled', 'cancel' => 'cancelled',
            'fail' => 'failed',
            'started', 'start', 'running', 'in_progress', 'in-progress' => 'active',
            'pending' => 'draft',
            'awaiting-approval', 'needs_approval', 'needs-approval', 'pending_approval' => 'awaiting_approval',
            default => strtolower(trim($value)),
        };

        return self::from($canonical);
    }
}
