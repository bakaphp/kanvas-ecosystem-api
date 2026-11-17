<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\ReachOutToNewLeadAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

/**
 * The agent's row is the switch (`Agent::forNewLeads()`), not the rule. With nobody reaching out the
 * new lead's AI mode is not applied here: ApplyLeadAiModeActivity asks the same question when it
 * runs and applies it then, so it is applied once either way.
 */
#[WorkflowAction(
    name: 'Agents Reach Out To New Lead',
    description: 'The company\'s agent with reach-out switched on sends a new lead its first message. A company '
        . 'where no agent has it on is skipped (runs on the lead created event)',
)]
class ReachOutToNewLeadActivity extends KanvasActivity
{
    // A retry after a partial send would message the customer twice.
    public $tries = 1;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Lead) {
            return $this->skipWorkflow('Not a lead');
        }

        $agent = Agent::forNewLeads($entity->app, $entity->company);

        if ($agent === null) {
            return $this->skipWorkflow('No agent of the company reaches out to new leads');
        }

        return new ReachOutToNewLeadAction($entity, $agent)->execute();
    }
}
