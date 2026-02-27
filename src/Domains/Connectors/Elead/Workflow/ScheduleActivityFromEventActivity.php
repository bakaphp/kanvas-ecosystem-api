<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\ScheduleEleadActivityFromEventAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ScheduleActivityFromEventActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Event $event, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $event->company;

        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return $this->executeIntegration(
            entity: $event,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: function ($event, $app, $integrationCompany, $additionalParams) {
                $lead = $this->findLeadFromEvent($event);
                if (! $lead) {
                    return $this->failWorkflow([
                        'error' => 'No Lead found linked to this Event',
                        'event_id' => $event->getId(),
                    ]);
                }

                $action = new ScheduleEleadActivityFromEventAction(
                    $event,
                    $lead,
                    $additionalParams
                );
                $result = $action->execute();

                return [
                    'message' => 'Activity scheduled successfully in eLead',
                    'activity_id' => $result->activityId,
                    'event_id' => $event->getId(),
                    'lead_id' => $lead->getId(),
                ];
            },
            company: $company,
        );
    }

    protected function findLeadFromEvent(Event $event): ?Lead
    {
        $resource = EventResource::where('event_id', $event->getId())
            ->where('apps_id', $event->apps_id)
            ->where('companies_id', $event->companies_id)
            ->where('resources_type', Lead::class)
            ->first();

        if ($resource) {
            return Lead::find($resource->resources_id);
        }

        return null;
    }
}
