<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Actions\TriggerLeadIntelligenceAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

/**
 * A lead's AI mode only means something where an agent works the leads, so a company no active
 * agent serves is left alone.
 */
#[WorkflowAction(
    name: 'Triggers Apply Lead AI Mode',
    description: 'Applies what an AI trigger (new lead, a person took over, hand-off, closed) means for the lead\'s '
        . 'AI mode. A company no active agent serves is skipped (runs on trigger-ai, param trigger_type)',
    requiredParams: ['trigger_type'],
)]
class ApplyLeadAiModeActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $triggerType = (int) ($params['trigger_type'] ?? 0);

        if (! $entity instanceof Lead || $triggerType === 0) {
            return $this->skipWorkflow('Not a lead trigger');
        }

        if (! Agent::query()->servingCompany($entity->app, $entity->company)->exists()) {
            return $this->skipWorkflow('No active agent serves the company');
        }

        if ($triggerType === TriggersEnum::NEW_LEAD->value
            && ! empty($params[Lead::REACH_OUT_APPLIES_MODE])
            && Agent::forNewLeads($entity->app, $entity->company) !== null
        ) {
            return $this->skipWorkflow('The agent reaching out to the new lead applies its mode');
        }

        return new TriggerLeadIntelligenceAction($entity, $triggerType)->execute();
    }
}
