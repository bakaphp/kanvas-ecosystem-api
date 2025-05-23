<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\SyncLeadToZohoAction;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * @todo refactor move core logic to SyncLeadWithZohoAction
 */
class ZohoLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    //public $tries = 5;
    public $tries = 3;

    /**
     * @param Lead $lead
     */
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        //$zohoLead = ZohoLead::fromLead($lead);
        //$zohoData = $zohoLead->toArray();
        //$company = Companies::getById($lead->companies_id);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::ZOHO,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $syncLeadWithZoho = new SyncLeadToZohoAction($app, $lead);
                $zohoLead = $syncLeadWithZoho->execute();

                return [
                    'zohoLeadId' => $lead->getId(),
                    'zohoRequest' => $zohoLead,
                    'leadId' => $lead->getId(),
                    'status' => $lead->status()->first()->name,
                ];
            },
            company: $lead->company,
        );
    }
}
