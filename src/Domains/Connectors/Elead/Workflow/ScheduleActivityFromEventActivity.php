<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\ScheduleEleadActivityFromEventAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
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
                /** @var Lead|null $lead */
                $lead = $event->linkedResource(Lead::class);
                if (! $lead) {
                    return $this->failWorkflow([
                        'error' => 'No Lead found linked to this Event',
                        'event_id' => $event->getId(),
                    ]);
                }

                try {
                    $result = new ScheduleEleadActivityFromEventAction(
                        $event,
                        $lead,
                        $additionalParams
                    )->execute();
                } catch (ValidationException $e) {
                    return $this->failWorkflow([
                        'error' => $e->getMessage(),
                        'event_id' => $event->getId(),
                    ]);
                }

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
}
