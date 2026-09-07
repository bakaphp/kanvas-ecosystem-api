<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\AddOutBoundPhoneCallActivityToLeadAction;
use Kanvas\Connectors\SalesAssist\Actions\CreateSocialChannelForContactAction;
use Kanvas\Connectors\SalesAssist\Actions\EnsureFirstMessageEnabledAction;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;

#[WorkflowAction(
    name: 'Sales Assist Agent Reach Out To Lead',
    description: 'Validates Sales Assist first-message eligibility, then has the agent reach out to the lead.',
)]
final class SalesAssistAgentReachOutActivity extends AgentReachOutActivity
{
    public $tries = 1;

    protected function validateBeforeReachOut(Lead $lead, Apps $app, array $params): void
    {
        new EnsureFirstMessageEnabledAction($lead)->execute();
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function afterReachOut(Lead $lead, Apps $app, array $params, array $result): void
    {
        $this->attachReachOutMessagesToProtocolChannels($lead, $app, $params, $result);

        $messageId = (int) ($result['message_ids_sent'][0] ?? 0);
        if (! $lead->get('downloaded_from_eleads') || $messageId === 0) {
            return;
        }

        try {
            $message = Message::getById($messageId, $app);

            new AddOutBoundPhoneCallActivityToLeadAction($lead, $message)
                ->execute('Sally Takes Over', 'Sally stops the clock');
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * Agent Reach Out creates the outbound messages, while SalesAssist also
     * exposes the legacy per-protocol channels in the lead history.
     *
     * @param array<string, mixed> $result
     */
    private function attachReachOutMessagesToProtocolChannels(
        Lead $lead,
        Apps $app,
        array $params,
        array $result
    ): void {
        $messageIds = array_unique(array_merge(
            array_map('intval', $result['message_ids_sent'] ?? []),
            array_map('intval', $result['message_ids_scheduled'] ?? []),
        ));
        if ($messageIds === []) {
            return;
        }

        $agentId = (int) ($params['agent_id'] ?? $lead->company->get(
            CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value
        ));
        if ($agentId === 0) {
            return;
        }

        foreach ($messageIds as $messageId) {
            try {
                $message = Message::getById($messageId, $app);
                $protocol = strtolower((string) $message->get('communicationChannel'));
                $contact = $this->contactForProtocol($lead, $protocol);
                if (! $contact instanceof Contact) {
                    continue;
                }

                $channelResult = new CreateSocialChannelForContactAction(
                    $contact,
                    $app,
                    ['agent_id' => $agentId],
                    $lead,
                )->execute();
                $channelId = (int) ($channelResult['channel_id'] ?? 0);
                if ($channelId > 0) {
                    Channel::getById($channelId, $app)->addMessage($message);
                }
            } catch (Exception $exception) {
                report($exception);
            }
        }
    }

    private function contactForProtocol(Lead $lead, string $protocol): ?Contact
    {
        return match ($protocol) {
            'sms' => $lead->people?->getCellPhones()->first(),
            'email' => $lead->people?->getEmails()->first(),
            default => null,
        };
    }

    protected function shouldThrowIntegrationException(): bool
    {
        return true;
    }
}
