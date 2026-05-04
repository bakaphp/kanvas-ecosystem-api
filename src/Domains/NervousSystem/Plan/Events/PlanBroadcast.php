<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Override;

class PlanBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const string CHANGE_CREATED = 'created';
    public const string CHANGE_UPDATED = 'updated';
    public const string CHANGE_APPROVED = 'approved';
    public const string CHANGE_REJECTED = 'rejected';
    public const string CHANGE_DELETED = 'deleted';
    public const string CHANGE_TASK_ADDED = 'task_added';
    public const string CHANGE_TASK_STATUS_CHANGED = 'task_status_changed';

    public function __construct(
        public Plan $plan,
        public string $changeType,
        public ?Task $task = null,
        public ?string $previousStatus = null,
    ) {
    }

    /**
     * Channels (frontend pairs must match exactly):
     *   - company-{cid}-app-{aid}-plans
     *   - company-{cid}-app-{aid}-plan-{planId}
     *   - company-{cid}-app-{aid}-agent-{agentId}-plans  (only when plan has an agent)
     *
     * @return array<int, Channel>
     */
    #[Override]
    public function broadcastOn(): array
    {
        $channels = [
            new Channel(
                'company-' . $this->plan->companies_id
                . '-app-' . $this->plan->apps_id
                . '-plans'
            ),
            new Channel(
                'company-' . $this->plan->companies_id
                . '-app-' . $this->plan->apps_id
                . '-plan-' . $this->plan->id
            ),
        ];

        if ($this->plan->agent_id !== null) {
            $channels[] = new Channel(
                'company-' . $this->plan->companies_id
                . '-app-' . $this->plan->apps_id
                . '-agent-' . $this->plan->agent_id
                . '-plans'
            );
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'plan.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'change_type' => $this->changeType,
            'previous_status' => $this->previousStatus,
            'plan' => [
                'id' => $this->plan->id,
                'agent_id' => $this->plan->agent_id,
                'status' => $this->plan->status,
                'priority' => $this->plan->priority,
                'completion_pct' => $this->plan->completion_pct,
            ],
            'task' => $this->task !== null ? [
                'id' => $this->task->id,
                'status' => $this->task->status,
            ] : null,
        ];
    }
}
