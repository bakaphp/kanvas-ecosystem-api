<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Override;

/**
 * What a task worker is not allowed to touch.
 *
 * A worker exists to move ONE task. Left with the orchestrator's full toolset it can reassign its own
 * plan, close it, spawn more workers, or email a customer — at which point it is not a worker, it is a
 * second orchestrator with no supervision. Hermes draws the same boundary for the same reason and its
 * blocked set is nearly identical: no recursive delegation, no memory writes, no outbound, no cron.
 *
 * Enforced in `MergesRegisteredTools`, where every toolset is assembled, so a denied tool is never put
 * in front of the model rather than being refused after it tries. Prompting a model not to use a tool
 * it can see is a suggestion; not giving it the tool is a boundary.
 *
 * Scoping and reset come from {@see ScopedToolPolicy}; `RunTaskWorkerJob` wraps one turn in it.
 */
class WorkerToolPolicy extends ScopedToolPolicy
{
    private static bool $active = false;

    /**
     * Denied by name. Matching is exact against the snake_case id the model calls, because that is the
     * only identifier both tool trees agree on.
     *
     * @var list<string>
     */
    private const array DENIED = [
        // Plan and task mutation — a worker reports on its task, it does not run the board.
        'assign_nervous_system_plan',
        'assign_nervous_system_task',
        'create_nervous_system_plan',
        'update_nervous_system_plan',
        'delete_nervous_system_plan',
        'move_nervous_system_plan',
        'add_nervous_system_task',
        'delete_nervous_system_task',
        'update_nervous_system_project',

        // Further delegation — depth stays at one until nested orchestration is its own feature.
        'dispatch_coding_task',
        'retry_coding_job',
        'dispatch_long_task',
        'hire_agent',
        'grant_agent_tools',
        'find_and_add_nervous_system_member',

        // Outbound. A worker that emails a customer has escaped every approval gate on the plan.
        'send_email',
        'send_sms',
        'send_email_to_user',
        'send_slack_direct_message',
        'create_message',
        'hand_off_lead',

        // Scheduling — work booked in the parent's name that nobody approved.
        'cronjob',
        'schedule_agent_task',
        'schedule_reminder',
        'cancel_scheduled_action',

        // Self-modification. Never, and least of all from an unsupervised worker.
        'update_agent_instructions',
    ];

    /**
     * @return list<string>
     */
    public static function deniedToolNames(): array
    {
        return self::DENIED;
    }

    #[Override]
    public static function permits(string $toolName): bool
    {
        return ! in_array($toolName, self::DENIED, true);
    }

    #[Override]
    public static function isActive(): bool
    {
        return self::$active;
    }

    #[Override]
    protected static function setActive(bool $active): void
    {
        self::$active = $active;
    }
}
