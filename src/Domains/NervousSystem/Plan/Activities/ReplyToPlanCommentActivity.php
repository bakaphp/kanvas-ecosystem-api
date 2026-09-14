<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Jobs\PushCommentToKanbanJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * For routing a DIFFERENT channel's messages to a plan's agent — a per-company decision, which is what
 * a rule is for. A plan's own board is handled natively by `WakePlanAgentOnChannelCommentListener`;
 * wiring this to it as well makes the agent answer twice.
 */
#[WorkflowAction(
    name: 'Reply To Plan Comment',
    description: 'Route another channel\'s comments to the agent working the linked plan, answering on the '
        . 'board. A plan\'s own board is already handled natively — wiring this for it answers twice.',
)]
class ReplyToPlanCommentActivity extends KanvasActivity
{
    public $tries = 1;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams): array {
                $plan = $this->resolvePlan($message);

                if ($plan === null) {
                    return ['message' => 'Message not linked to a Plan', 'entity' => null];
                }

                if ($plan->agent_id === null) {
                    return ['message' => 'Plan has no assigned agent', 'entity' => null];
                }

                // Per-agent kill switch.
                if (! (bool) ($plan->agent?->is_active ?? false)) {
                    return ['message' => 'Plan\'s assigned agent is inactive', 'entity' => null];
                }

                // Loop guard — only skip messages from the plan's OWN assigned
                // agent. Other agents posting on this plan's channel still
                // trigger a wake-up so multi-agent collaboration works.
                $assignedAgentUserId = $plan->agent?->user?->getId();
                if ($assignedAgentUserId !== null && (int) $message->users_id === (int) $assignedAgentUserId) {
                    return ['message' => 'Skipped: message is from this plan\'s assigned agent', 'entity' => null];
                }

                $content = is_array($message->message) ? (string) ($message->message['content'] ?? '') : $message->message;
                if ($content === '') {
                    return ['message' => 'Empty message body', 'entity' => null];
                }

                // Kanban-driven runtime agents (Hermes) are steered by comments on the board, not
                // chat turns. Route the human's comment to the card (+ unblock if escalated) instead
                // of waking via chat — the worker replays the thread on its next spawn.
                $deployment = $plan->agent?->activeDeployment;
                if ($deployment instanceof AgentDeployment
                    && $deployment->isRunning()
                    && AgentProviderEnum::forDeployment($deployment)->isHermes()
                ) {
                    PushCommentToKanbanJob::dispatch($plan, $content, (int) $message->users_id);

                    return [
                        'message' => 'Comment pushed to kanban board',
                        'entity' => $plan,
                        'plan_id' => $plan->id,
                        'plan_uuid' => $plan->uuid,
                    ];
                }

                $plan->emitLedgerEvent(
                    'plan.agent.wake_dispatched',
                    payload: [
                        'agent_id' => $plan->agent_id,
                        'reason' => WakeAgentForPlanJob::REASON_COMMENT,
                        'source' => 'activity',
                        'message_id' => $message->id,
                    ],
                );

                WakeAgentForPlanJob::dispatch(
                    $plan,
                    WakeAgentForPlanJob::REASON_COMMENT,
                    $content,
                );

                return [
                    'message' => 'Wake-up dispatched',
                    'entity' => $plan,
                    'plan_id' => $plan->id,
                    'plan_uuid' => $plan->uuid,
                    'reason' => WakeAgentForPlanJob::REASON_COMMENT,
                ];
            },
            company: $message->company,
        );
    }

    protected function resolvePlan(Message $message): ?Plan
    {
        // Two-step lookup: first via the channel pivot (the typical path —
        // a message is associated with a Channel that has the Plan as its
        // entity), then via the message's own polymorphic entity if set.
        $channel = $message->channels->first();

        if ($channel !== null && $channel->entity_namespace === Plan::class) {
            return Plan::query()
                ->where('id', (int) $channel->entity_id)
                ->where('is_deleted', 0)
                ->first();
        }

        if ($message->entity_namespace === Plan::class && $message->entity_id !== null) {
            return Plan::query()
                ->where('id', $message->entity_id)
                ->where('is_deleted', 0)
                ->first();
        }

        return null;
    }
}
