<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Scheduling\Actions\CancelScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Cancels a pending scheduled action the current user owns — the whole series for a recurring one.
 * Resolves the id inside the current tenant + user so an agent can never cancel another user's or
 * another company's schedule.
 */
#[AgentTool(name: 'Cancel Scheduled Action', category: 'nervous_system')]
class CancelScheduledActionTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'cancel_scheduled_action',
            description: 'Cancel a pending reminder or scheduled task by its id (from list_scheduled_actions). '
                . 'Cancels the whole series for a recurring one.',
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
                name: 'scheduled_action_id',
                type: PropertyType::INTEGER,
                description: 'The id of the scheduled action to cancel.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $scheduled_action_id): array
    {
        /** @var ScheduledAction|null $action */
        $action = ScheduledAction::query()
            ->where('id', $scheduled_action_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->forUser($this->user->getId())
            ->where('is_deleted', 0)
            ->first();

        if ($action === null) {
            return [
                'status' => 'error',
                'message' => "No scheduled action #{$scheduled_action_id} for the current user. "
                    . 'Use list_scheduled_actions to see valid ids.',
            ];
        }

        $cancelled = new CancelScheduledActionAction($action)->execute();

        return [
            'status' => 'success',
            'scheduled_action_id' => $cancelled->getId(),
            'message' => 'Cancelled.',
        ];
    }
}
