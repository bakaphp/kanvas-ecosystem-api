<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\EnsureFirstMessageEnabledAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutAction;
use Kanvas\Intelligence\Agents\Actions\Outreach\ReachOutToNewLeadAction;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Attributes\WorkflowAction;

/**
 * For a rule a tenant wrote around the reach-out (its own conditions, its own agent). A company's
 * plain reach-out needs no rule of its own: the app default rule runs ReachOutToNewLeadActivity.
 */
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

    protected function shouldDeferDelivery(Lead $lead): ?bool
    {
        return ReachOutToNewLeadAction::defersDelivery($lead);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function afterReachOut(Lead $lead, Apps $app, array $params, array $result): void
    {
        if (AgentReachOutAction::reachedTheLead($result)) {
            AgentReachOutAction::announceReachOut(
                $lead,
                Agent::getById((int) $result['agent_id'], $app),
                $result
            );
        }
    }

    protected function shouldThrowIntegrationException(): bool
    {
        return true;
    }
}
