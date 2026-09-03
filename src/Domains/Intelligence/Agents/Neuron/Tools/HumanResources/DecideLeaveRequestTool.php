<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Actions\DecideLeaveRequestAction;
use Kanvas\HumanResources\Leave\Enums\LeaveDecisionEnum;
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
 * Closes the leave loop. request_leave files a PENDING request and, until this existed, nothing in
 * the agent could ever approve it — every request the agent took sat pending until a human opened
 * the web UI.
 *
 * Runs the same DecideLeaveRequestAction as the GraphQL mutation, so approving moves the days from
 * pending to used on the balance and rejecting releases them, identically to the UI.
 */
#[AgentTool(name: 'Decide Leave Request', category: 'human_resources')]
class DecideLeaveRequestTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'decide_leave',
            description: 'Approves or rejects a PENDING time-off request. Allowed for a company administrator or the '
                . 'employee\'s own manager. Get the leave_request_id from list_leave_requests first. Approving moves '
                . 'the days from pending to used; rejecting gives them back. Returns updated=false with a reason if '
                . 'you are not allowed, the id is unknown, or the request was already decided.',
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
            new ToolProperty(
                name: 'decision',
                type: PropertyType::STRING,
                description: 'Either "approve" or "reject".',
                required: true,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'Optional note recorded on the employee\'s history with the decision.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $leave_request_id, string $decision, ?string $note = null): array
    {
        $choice = LeaveDecisionEnum::tryFrom(strtolower($decision));

        if ($choice === null) {
            return ['updated' => false, 'message' => 'decision must be either "approve" or "reject".'];
        }

        $request = $this->resolveLeaveRequestOrError($leave_request_id);

        if (! $request instanceof LeaveRequest) {
            return $request;
        }

        if ($denied = $this->requireLeaveDeciderOrError($request)) {
            return $denied;
        }

        try {
            $decided = new DecideLeaveRequestAction(
                $request,
                $choice,
                $this->actingEmployee(),
                $note,
            )->execute();
        } catch (HumanResourcesException $e) {
            return ['updated' => false, 'message' => $e->getMessage()];
        }

        return [
            'updated' => true,
            'decision' => $choice->value,
            'request' => $this->presentLeaveRequest($decided),
        ];
    }
}
