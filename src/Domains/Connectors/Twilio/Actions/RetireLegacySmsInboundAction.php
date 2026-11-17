<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Communication\Connections\Actions\RetireLegacyInboundAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\Twilio\Handlers\TwilioHandlers;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * A company's texts used to reach one inbound receiver and its delivery receipts another, and one
 * rule named the agent that answered every text. With the number on a connection those receivers
 * get no more traffic and the rule has nothing to run. The agent the rule named keeps answering: a
 * number nobody owns yet is given to it.
 */
final class RetireLegacySmsInboundAction
{
    /**
     * The classes are deleted; these are the names the `actions` rows of a company not yet moved
     * still carry.
     */
    public const array RECEIVER_JOBS = [
        'Kanvas\\Connectors\\Twilio\\Webhooks\\ProcessTwilioWebhookJob',
        'Kanvas\\Connectors\\Twilio\\Webhooks\\ProcessTwilioMessageStatusWebhookJob',
    ];

    public const string RESPONDER_ACTIVITY = 'Kanvas\\Connectors\\Twilio\\Workflows\\AgentChannelResponderActivity';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return list<string> what was retired, for the setup report
     */
    public function execute(): array
    {
        // The rule answered the company's sending number. An agent's own number already has its
        // owner, and retiring the rule from there would leave the sending number with nobody.
        $sendingNumber = TwilioHandlers::sendingNumber($this->connection->company);
        $answeredByRule = $sendingNumber === null || $sendingNumber === $this->connection->account_key;

        return new RetireLegacyInboundAction(
            $this->connection,
            self::RECEIVER_JOBS,
            $answeredByRule ? [self::RESPONDER_ACTIVITY] : []
        )->execute(
            fn (Rule $rule): ?string => $this->giveNumberTo((int) ($rule->params['agent_id'] ?? 0))
                ? 'agent ' . $rule->params['agent_id'] . ' owns ' . $this->connection->account_key
                : null
        );
    }

    /**
     * Only a number nobody owns, to an agent of this company that has none: the owner an agent
     * already declared is never overridden by a rule.
     */
    private function giveNumberTo(int $agentId): bool
    {
        $agent = $this->connection->candidateOwner($agentId);

        if ($agent === null || $agent->smsPhoneNumber() !== null) {
            return false;
        }

        $agent->forceFill([
            'sms_config' => [...($agent->sms_config ?? []), 'phone_number' => $this->connection->account_key],
        ])->saveQuietly();
        $this->connection->handTo($agent);

        return true;
    }
}
