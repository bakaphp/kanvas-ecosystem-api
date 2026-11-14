<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Communication\Connections\Actions\RetireLegacyInboundAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * An address used to reach its own receiver (the company's on one job, each agent mailbox on
 * another), the domain's events two more, and a rule named the agent that answered the company's
 * mail. With the address on a connection those receivers get no more traffic and the rule has
 * nothing to run. The agent the rule named keeps answering the company's address, as its customer
 * line; an agent's own mailbox already names its agent.
 */
final class RetireLegacyEmailInboundAction
{
    public const string COMPANY_INBOX_JOB = 'Kanvas\\Connectors\\Mailgun\\Webhooks\\AgentProcessEmailWebhookJob';
    public const string AGENT_INBOX_JOB = 'Kanvas\\Connectors\\Mailgun\\Webhooks\\AgentInboxWebhookJob';

    /**
     * The classes are deleted; these are the names the `actions` rows of a company not yet moved
     * still carry.
     */
    public const array RECEIVER_JOBS = [
        self::COMPANY_INBOX_JOB,
        self::AGENT_INBOX_JOB,
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\EmailDeliveryWebhookJob',
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\EmailOpenedWebhookJob',
    ];

    public const string RESPONDER_ACTIVITY = 'Kanvas\\Connectors\\Mailgun\\Workflows\\AgentChannelResponderActivity';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return list<string> what was retired, for the setup report
     */
    public function execute(): array
    {
        $isCompanyAddress = $this->isCompanyAddress();

        // The responder rule and the company inbox belong to the company's address; a mailbox's
        // connection only retires that mailbox's own receiver.
        return new RetireLegacyInboundAction(
            $this->connection,
            self::RECEIVER_JOBS,
            $isCompanyAddress ? [self::RESPONDER_ACTIVITY] : []
        )->execute(
            fn (Rule $rule): ?string => $this->giveCustomerLineTo((int) ($rule->params['agent_id'] ?? 0))
                ? 'agent ' . $rule->params['agent_id'] . ' answers ' . $this->connection->account_key
                : null,
            fn (ReceiverWebhook $receiver): bool => $this->served($receiver, $isCompanyAddress)
        );
    }

    private function served(ReceiverWebhook $receiver, bool $isCompanyAddress): bool
    {
        if ($receiver->action?->model_name !== self::AGENT_INBOX_JOB) {
            return $isCompanyAddress;
        }

        $mailbox = strtolower(trim((string) (
            $receiver->configuration[ReceiverConfigurationEnum::MAILBOX_ADDRESS->value] ?? ''
        )));

        return $mailbox === $this->connection->account_key;
    }

    /**
     * Only an address nobody owns, to an agent of this company that has no mailbox: an agent's own
     * mailbox is never traded for the company's address.
     */
    private function giveCustomerLineTo(int $agentId): bool
    {
        $agent = $this->connection->candidateOwner($agentId);

        if ($agent === null || new AgentMailboxService()->hasMailbox($agent)) {
            return false;
        }

        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $this->connection->account_key);
        $agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::CUSTOMER_LINE->value);
        $this->connection->handTo($agent, [Connection::PERSONAL => false]);

        return true;
    }

    private function isCompanyAddress(): bool
    {
        return MailgunHandler::sendingAddress(
            $this->connection->app,
            $this->connection->company
        ) === $this->connection->account_key;
    }
}
