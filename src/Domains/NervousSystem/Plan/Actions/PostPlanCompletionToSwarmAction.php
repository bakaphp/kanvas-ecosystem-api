<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Throwable;

class PostPlanCompletionToSwarmAction
{
    public function __construct(
        protected readonly Plan $plan,
    ) {
    }

    public function execute(): ?Message
    {
        try {
            $agent = $this->plan->agent;

            if ($agent === null) {
                return null;
            }

            $swarmMember = AgentSwarmMember::query()
                ->where('agent_id', $agent->getId())
                ->where('is_deleted', 0)
                ->first();

            if ($swarmMember === null) {
                return null;
            }

            $swarm = $swarmMember->swarm;
            $swarmChannel = $swarm?->socialChannels->first();

            if ($swarmChannel === null) {
                return null;
            }

            $agentUser = $agent->user;
            if ($agentUser === null) {
                return null;
            }

            $summary = $this->buildSummary($agent->name);

            $messageType = new CreateMessageTypeAction(
                new MessageTypeInput(
                    apps_id: $this->plan->app->getId(),
                    languages_id: 1,
                    name: 'plan_milestone',
                    verb: 'plan_milestone',
                    template: '{{message}}',
                    templates_plura: '{{message}}',
                ),
            )->execute();

            $message = new CreateMessageAction(
                new MessageInput(
                    app: $this->plan->app,
                    company: $this->plan->company,
                    user: $agentUser,
                    type: $messageType,
                    message: [
                        'content' => $summary,
                        'from_me' => true,
                        'plan_id' => $this->plan->id,
                        'plan_uuid' => $this->plan->uuid,
                        'plan_status' => $this->plan->status,
                    ],
                ),
            )->execute();

            $swarmChannel->addMessage($message, $agentUser);

            $this->plan->emitLedgerEvent(
                'plan.swarm_milestone_posted',
                payload: [
                    'agent_id' => $agent->getId(),
                    'swarm_id' => $swarm->getId(),
                    'channel_id' => $swarmChannel->getId(),
                    'message_id' => $message->id,
                    'plan_status' => $this->plan->status,
                ],
            );

            return $message;
        } catch (Throwable $e) {
            Log::error('[NS:PostPlanCompletionToSwarm] failed', [
                'plan_id' => $this->plan->id,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
            report($e);

            return null;
        }
    }

    protected function buildSummary(string $agentName): string
    {
        $statusLabel = match ($this->plan->status) {
            'done' => '✅ completed',
            'failed' => '❌ failed',
            'cancelled' => '⊘ cancelled',
            default => $this->plan->status,
        };

        $output = is_array($this->plan->output) ? ($this->plan->output['summary'] ?? null) : null;

        return sprintf(
            'Plan #%d "%s" %s by %s%s',
            $this->plan->id,
            $this->plan->title,
            $statusLabel,
            $agentName,
            is_string($output) && $output !== '' ? "\n> {$output}" : '',
        );
    }
}
