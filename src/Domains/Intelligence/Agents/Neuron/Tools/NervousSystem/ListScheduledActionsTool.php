<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesConversationHuman;
use Kanvas\Intelligence\Sessions\Models\Session;
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
    use ResolvesConversationHuman;

    public function __construct(private readonly ?Session $session = null)
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

        // The owner is the human in the conversation (matching how the schedule_* tools set it), not the
        // agent's own context user — otherwise the agent lists its own actions, never the human's.
        $owner = $this->conversationHuman($this->session) ?? $this->user;

        $rows = ScheduledAction::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->forUser($owner->getId())
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
