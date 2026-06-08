<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Override;

class PlanBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Plan $plan,
        public PlanChangeTypeEnum $changeType,
        public ?Task $task = null,
        public ?string $previousStatus = null,
        public bool $fromSync = false,
    ) {
    }

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
            'change_type' => $this->changeType->value,
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
