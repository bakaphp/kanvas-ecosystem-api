<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as LeadSourceDTO;
use Kanvas\Workflow\KanvasActivity;

class TainoSourcesSubSourcesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        if (! key_exists('payload', $params)) {
            return [
                'error' => 'Payload is required',
            ];
        }
        $payload = $params['payload'] ?? [];

        if (! $payload) {
            return [
                'error' => 'Payload is required',
            ];
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

        $sourceName = $params['sub_source'] == 'Facebook' || $params['sub_source'] == 'Instagram' ? 'Meta' : $params['source'];
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
            'success' => true,
        ];
    }
}
