<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Turn Off Closed Lead Follow Up',
    description: 'Turns the AI follow-up off when the lead is closed sold or closed not sold. '
        . 'Leaves the AI mode untouched; sends nothing.',
)]
class TurnOffClosedLeadFollowUpActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $lead->closeSold() && ! $lead->closeNotSold()) {
            return [
                'success' => true,
                'message' => 'Skipped: lead is not closed sold or closed not sold',
                'lead_id' => $lead->getId(),
            ];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($lead) {
                $result = new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

                return [
                    'success' => true,
                    'message' => 'Follow up turned off',
                    'lead_id' => $lead->getId(),
                    ...$result,
                ];
            },
            company: $lead->company,
        );
    }
}
