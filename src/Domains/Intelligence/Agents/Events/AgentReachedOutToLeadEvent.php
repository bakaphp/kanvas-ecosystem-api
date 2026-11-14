<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * An agent's first message to a new lead was sent or scheduled. What a connector does with that (a
 * CRM activity, its own view of the conversation) hangs here, so the reach-out names no vendor.
 */
final class AgentReachedOutToLeadEvent
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $result what AgentReachOutAction returned, message ids included
     */
    public function __construct(
        public readonly Lead $lead,
        public readonly Agent $agent,
        public readonly array $result
    ) {
    }

    /**
     * The one way to say an agent reached out: what Kanvas does about it listens to the event, and
     * what an outside system needs to hear (a CRM timing the dealer's answer) is a rule its
     * integration puts on `agent-reached-out`.
     *
     * @param array<string, mixed> $result what AgentReachOutAction returned
     */
    public static function announce(Lead $lead, Agent $agent, array $result): void
    {
        self::dispatch($lead, $agent, $result);

        $lead->fireWorkflow(
            WorkflowEnum::AGENT_REACHED_OUT->value,
            true,
            [
                'app' => $lead->app,
                'company' => $lead->company,
                'agent_id' => $agent->getId(),
                'message_id' => (int) ($result['message_ids_sent'][0] ?? 0),
            ]
        );
    }
}
