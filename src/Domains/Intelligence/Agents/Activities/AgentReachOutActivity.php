<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Workflow-engine adapter for the outbound-first reach-out flow. Fires on
 * WorkflowEnum::CREATED + system_module=Lead. All logic lives in
 * AgentReachOutAction so it's testable without workflow-engine internals.
 */
#[WorkflowAction(
    name: 'Agent Reach Out To Lead',
    description: 'Has the agent reach out to a lead on its preferred channel. This CONTACTS the customer. '
        . 'Approval mode, if the company has it on, holds the message for a human first.',
)]
class AgentReachOutActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: fn () => new AgentReachOutAction($lead, $params)->execute(),
            company: $lead->company,
        );
    }
}
