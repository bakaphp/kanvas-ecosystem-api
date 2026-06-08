<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Models\Message;
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

            $message = new PostPlanActivityMessageAction(
                $this->plan,
                $summary,
                author: $agentUser,
                channel: $swarmChannel,
                verb: 'plan_milestone',
                extraPayload: [
                    'plan_id' => $this->plan->id,
                    'plan_uuid' => $this->plan->uuid,
                    'plan_status' => $this->plan->status,
                ],
            )->execute();

            if ($message === null) {
                return null;
            }

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
