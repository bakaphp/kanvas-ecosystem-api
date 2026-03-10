<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Support\EleadDebounce;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $lead->company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        $debounce = new EleadDebounce($app, $lead->company);
        if ($debounce->shouldSkip('push_lead', (string) $lead->getId())) {
            return [
                'message' => 'Debounced: Lead sync already in progress or recently completed',
                'lead_id' => $lead->getId(),
            ];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                $syncLead = new SyncLeadAction($lead)->execute();

                return [
                    'message' => 'Lead pushed successfully',
                    'entity' => $syncLead,
                ];
            },
            company: $lead->company,
        );
    }
}
