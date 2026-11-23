<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Exception;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * What SalesAssist adds once an agent's first message went out: the message shows on the legacy
 * per-protocol channels of the lead history. It must not fail the reach-out that already happened.
 */
final class RecordAgentReachOutAction
{
    /**
     * @param array<string, mixed> $result what AgentReachOutAction returned, `agent_id` included
     */
    public function __construct(
        private readonly Lead $lead,
        private readonly array $result
    ) {
    }

    public function execute(): void
    {
        $this->attachMessagesToProtocolChannels();
    }

    private function attachMessagesToProtocolChannels(): void
    {
        $messageIds = array_unique(array_merge(
            array_map('intval', $this->result['message_ids_sent'] ?? []),
            array_map('intval', $this->result['message_ids_scheduled'] ?? []),
        ));

        foreach ($messageIds as $messageId) {
            try {
                $message = Message::getById($messageId, $this->lead->app);
                $contact = $this->contactForProtocol(strtolower((string) $message->get('communicationChannel')));
                if (! $contact instanceof Contact) {
                    continue;
                }

                $channelResult = new CreateSocialChannelForContactAction(
                    $contact,
                    $this->lead->app,
                    ['agent_id' => (int) ($this->result['agent_id'] ?? 0)],
                    $this->lead,
                )->execute();
                $channelId = (int) ($channelResult['channel_id'] ?? 0);
                if ($channelId > 0) {
                    Channel::getById($channelId, $this->lead->app)->addMessage($message);
                }
            } catch (Exception $exception) {
                report($exception);
            }
        }
    }

    private function contactForProtocol(string $protocol): ?Contact
    {
        return match ($protocol) {
            'sms' => $this->lead->people?->getCellPhones()->first(),
            'email' => $this->lead->people?->getEmails()->first(),
            default => null,
        };
    }
}
