<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\AddOutBoundPhoneCallActivityToLeadAction;
use Kanvas\Connectors\SalesAssist\Actions\EnsureFirstMessageEnabledAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
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

    protected function shouldThrowIntegrationException(): bool
    {
        return true;
    }
}
