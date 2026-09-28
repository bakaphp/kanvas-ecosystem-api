<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Relays a person's decision on a plan that is waiting for one. The agent is the scribe, never the
 * approver: the sign-off is recorded against the HUMAN in the conversation, so the audit trail names
 * who actually said yes.
 *
 * The reviewer is deliberately NOT taken from `requestingHuman()`. That falls back to the turn's actor
 * when no person has been identified, and on every wake surface — project wake, worker wake, heartbeat
 * — the actor IS the agent's own user. Trusting it would hand the agent the approval it is supposed to
 * be asking for, which is exactly what happened on plan 25667 in prose.
 */
#[AgentTool(name: 'Approve Plan', category: 'nervous_system')]
class ApproveNervousSystemPlanTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ResolvesPlanForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'approve_nervous_system_plan',
            description: 'Record a PERSON\'s decision on a plan that is waiting at awaiting_approval, so '
                . 'the work can start (or be dropped). Call this ONLY after a human has actually said '
                . 'yes or no in the conversation — you are recording their decision, not making it. You '
                . 'cannot approve a plan you asked for or are assigned to, and you cannot approve on '
                . 'your own initiative because nobody answered: if the person has not replied, say so '
                . 'and wait.',
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
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan waiting at awaiting_approval.',
                required: true,
            ),
            new ToolProperty(
                name: 'approved',
                type: PropertyType::BOOLEAN,
                description: 'True when the person approved it, false when they rejected it. A rejected '
                    . 'plan is cancelled, not retried.',
                required: true,
            ),
            new ToolProperty(
                name: 'review_outcome',
                type: PropertyType::STRING,
                description: 'What the person actually said, in their words — the record of the decision.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, bool $approved, ?string $review_outcome = null): array
    {
        $plan = $this->resolvePlanOrError($plan_id);

        if (is_array($plan)) {
            return $plan;
        }

        if ($plan->status !== PlanStatusEnum::AWAITING_APPROVAL->value) {
            return [
                'approved' => false,
                'message' => sprintf(
                    'Plan %d is %s, not awaiting_approval — there is no decision to record. Do not call '
                    . 'this again for it.',
                    $plan->getId(),
                    (string) $plan->status,
                ),
            ];
        }

        $reviewer = $this->requestingUser;
        $actingAgentUserId = $this->contextAgent()?->user?->getId();

        if ($reviewer === null || ($actingAgentUserId !== null && $reviewer->getId() === $actingAgentUserId)) {
            return [
                'approved' => false,
                'message' => 'This run has no identified person, so there is nobody whose decision you '
                    . 'could be recording. Ask the human who owns this plan to approve it, and leave it '
                    . 'at awaiting_approval until they do.',
            ];
        }

        try {
            $decided = new ApprovePlanAction(
                $plan,
                $reviewer,
                approved: $approved,
                reviewOutcome: $review_outcome,
            )->execute();
        } catch (Throwable $e) {
            return ['approved' => false, 'message' => $e->getMessage()];
        }

        return [
            'plan_id' => $decided->getId(),
            'status' => $decided->status,
            'approved' => $approved,
            'reviewed_by_users_id' => $reviewer->getId(),
        ];
    }
}
