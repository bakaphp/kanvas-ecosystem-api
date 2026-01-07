<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as LeadSourceDTO;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class DealerAppCenterSubSourcesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        /**
         * for now this is harcoded for specific company
         */
        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $payload = $params['payload'] ?? [];

                if (! $payload) {
                    return [
                        "success" => false
                    ]
                }

                $form = collect($payload['custom_fields'] ?? [])
                    ->firstWhere('name', 'request');

                if ($params['sub_source'] == 'Vehicle Interest') {
                    if ($form['data'] == 'verificar disponibilidad') {
                        $lead->set('sub_source', 'Vehicle of Interest - Solicitud: Availability (Lead)');
                    } elseif ($form['data'] == 'test drive') {
                        $lead->set('sub_source', 'Vehicle of Interest - Solicitud: Test Drive (Lead)');
                    } elseif ($form['data'] == 'recorrido en video') {
                        $lead->set('sub_source', 'Vehicle of Interest - Solicitud: Video (Lead)');
                    } elseif ($form['data'] == 'baja de precio') {
                        $lead->set('sub_source', 'Vehicle of Interest - Solicitud: Price Drop (Lead)');
                    }
                } else {
                    $lead->set('sub_source', $params['sub_source']);
                }

                $sourceName = $params['sub_source'] == 'Facebook' || $params['sub_source'] == 'Instagram' ? 'Meta' : 'Dealer Website';
                $leadSourceDto = new LeadSourceDTO(
                    app: $lead->app,
                    company: $lead->company,
                    leads_types_id: $lead->leads_types_id,
                    name: $sourceName,
                    is_active: true,
                );

                $createLeadSourceAction = new CreateLeadSourceAction($leadSourceDto);
                $leadSource = $createLeadSourceAction->execute();

                $lead->leads_sources_id = $leadSource->getId();
                $lead->save();

                return [
                    'form' => $form,
                    'success' => true,
                ];
            },
            company: $lead->company,
        );
    }
}
