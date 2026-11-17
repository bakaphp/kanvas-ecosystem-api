<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Communication\Connections\Actions\RetireLegacyInboundAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * A lead's WhatsApp message used to be answered by a workflow rule that named the agent. The
 * number is a connection now and its owning agent answers, so the rule has nothing to run. The
 * agent the rule (or the receiver) named keeps answering: a number nobody owns yet is given to it.
 *
 * The receiver itself is not retired — it is the same row, already moved onto the communication
 * webhook, so the URL WaSender posts to never changed.
 */
final class RetireLegacyWhatsAppInboundAction
{
    /**
     * The class is deleted; this is the name the `actions` row of a company not yet moved carries.
     */
    public const string RESPONDER_ACTIVITY = 'Kanvas\\Connectors\\WaSender\\Workflows\\AgentChannelResponderActivity';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return list<string> what was retired, for the setup report
     */
    public function execute(): array
    {
        $receiverAgentId = (int) (WaSenderHandler::receiverFor($this->connection->app, $this->connection->company)
            ?->configuration['agent_id'] ?? 0);

        // No receiver jobs: the receiver was moved in place, there is nothing to switch off.
        $retired = new RetireLegacyInboundAction($this->connection, [], [self::RESPONDER_ACTIVITY])->execute(
            fn (Rule $rule): ?string => $this->carryOver($rule, $receiverAgentId)
        );

        if ($this->giveNumberTo($receiverAgentId)) {
            $retired[] = 'agent ' . $receiverAgentId . ' owns ' . $this->connection->account_key;
        }

        return $retired;
    }

    private function carryOver(Rule $rule, int $receiverAgentId): ?string
    {
        $agentId = (int) ($rule->params['agent_id'] ?? 0) ?: $receiverAgentId;
        $notes = [];

        if ($this->giveNumberTo($agentId)) {
            $notes[] = 'agent ' . $agentId . ' owns ' . $this->connection->account_key;
        }

        // Per-chat agents and chat filters were the rule's own; one number has one owner now.
        if (isset($rule->params['channelAgentMapping']) || ! empty($rule->params['filterByChannel'])) {
            $notes[] = 'rule ' . $rule->name . ' had per-chat settings that are not carried over';
        }

        return $notes === [] ? null : implode('; ', $notes);
    }

    /**
     * Only a number nobody owns, to an agent of this company that has none: the owner an agent
     * already declared is never overridden by a rule.
     */
    private function giveNumberTo(int $agentId): bool
    {
        $agent = $this->connection->candidateOwner($agentId);

        if ($agent === null || $agent->whatsappPhoneNumber() !== null) {
            return false;
        }

        $agent->forceFill([
            'whatsapp_config' => [...($agent->whatsapp_config ?? []), 'phone_number' => $this->connection->account_key],
        ])->saveQuietly();
        $this->connection->handTo($agent);

        return true;
    }
}
