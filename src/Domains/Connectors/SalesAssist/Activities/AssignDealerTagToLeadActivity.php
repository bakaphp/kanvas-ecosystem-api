<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\AssignDealerTagToLeadAction;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'SalesAssist Assign Dealer Tag To Lead',
    description: 'Tags a lead with the rooftop it belongs to when one company account runs several stores: '
        . 'by the lead owner\'s team first, otherwise by the vehicle of interest\'s stock number. Safe on every '
        . 'lead update: once the owner has decided the tag later runs skip, and a vehicle-decided tag is only '
        . 'looked at again when the owner or the stock number changes. Writes tags.',
)]
class AssignDealerTagToLeadActivity extends KanvasActivity
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
            integrationOperation: function () use ($lead) {
                $result = new AssignDealerTagToLeadAction($lead)->execute();

                if ($result['skipped']) {
                    return [
                        'message' => 'Dealer tag already assigned by ' . $result['trigger'],
                        'lead_id' => $lead->getId(),
                    ];
                }

                if ($result['tag'] === null) {
                    return [
                        'message' => 'No dealer tag matched the lead owner or vehicle of interest',
                        'lead_id' => $lead->getId(),
                        'config_key' => ConfigurationEnum::LEAD_DEALER_TAGS->value,
                    ];
                }

                return [
                    'success' => true,
                    'lead_id' => $lead->getId(),
                    'tag' => $result['tag'],
                    'trigger' => $result['trigger'],
                ];
            },
            company: $lead->company,
        );
    }
}
