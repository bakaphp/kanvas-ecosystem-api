<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lists the current user's pending scheduled actions so the agent can answer "what have you got
 * scheduled for me?" and pick an id to cancel.
 */
#[AgentTool(name: 'List Scheduled Actions', category: 'nervous_system')]
class ListScheduledActionsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_scheduled_actions',
            description: 'List the pending reminders and scheduled tasks for the current user, with their ids '
                . 'and next fire times. Use before cancelling so you have the right id.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max rows to return (default 25).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null): array
    {
        $limit = max(1, min($limit ?? 25, 100));

        $rows = ScheduledAction::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->forUser($this->user->getId())
            ->pending()
            ->orderBy('run_at')
            ->limit($limit)
            ->get();

        $actions = $rows->map(fn (ScheduledAction $action): array => [
            'id' => $action->getId(),
            'type' => $action->action_type,
            'fires_at' => $action->run_at->toIso8601String(),
            'recurring' => $action->isRecurring(),
            'recurrence_cron' => $action->recurrence_cron,
            'summary' => Str::limit(
                (string) ($action->payload['message'] ?? $action->payload['instruction'] ?? ''),
                120,
            ),
        ])->all();

        return [
            'status' => 'success',
            'count' => count($actions),
            'actions' => $actions,
        ];
    }
}
