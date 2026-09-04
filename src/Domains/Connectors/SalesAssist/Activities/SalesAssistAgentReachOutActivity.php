<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\EnsureFirstMessageEnabledAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
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

    protected function shouldThrowIntegrationException(): bool
    {
        return true;
    }
}
