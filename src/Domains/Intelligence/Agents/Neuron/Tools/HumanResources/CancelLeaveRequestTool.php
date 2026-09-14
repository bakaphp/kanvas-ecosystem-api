<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Actions\CancelLeaveRequestAction;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesLeaveForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Withdraws a request the agent (or the employee) filed. Cancelling hands the days back — pending
 * days for a request still awaiting a decision, used days for one already approved — so a mistaken
 * booking does not quietly cost someone their balance for the year.
 */
#[AgentTool(name: 'Cancel Leave Request', category: 'human_resources')]
class CancelLeaveRequestTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'cancel_leave',
            description: 'Cancels a pending or approved time-off request and returns the days to the employee\'s '
                . 'balance. Allowed for a company administrator or the employee whose leave it is. Get the '
                . 'leave_request_id from list_leave_requests first. Returns updated=false with a reason if you are '
                . 'not allowed, the id is unknown, or the request was already rejected or cancelled.',
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
                name: 'leave_request_id',
                type: PropertyType::INTEGER,
                description: 'The request id, from list_leave_requests.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $leave_request_id): array
    {
        $request = $this->resolveLeaveRequestOrError($leave_request_id);

        if (! $request instanceof LeaveRequest) {
            return $request;
        }

        if ($denied = $this->requireLeaveCancellerOrError($request)) {
            return $denied;
        }

        try {
            $cancelled = new CancelLeaveRequestAction($request)->execute();
        } catch (HumanResourcesException $e) {
            return ['updated' => false, 'message' => $e->getMessage()];
        }

        return [
            'updated' => true,
            'request' => $this->presentLeaveRequest($cancelled),
        ];
    }
}
