<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\ScheduleAppointmentFromEventAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * An unmapped lead or a dead session is a configuration state, not a fault: the action's
 * ValidationException becomes a FAILED workflow here instead of a Sentry report.
 */
#[WorkflowAction(
    name: 'VinSolution Schedule Appointment From Event',
    description: 'When an appointment Event is created on a lead, books it in VinSolutions and stores the '
        . 'appointment id back on the Event so it is not booked twice. Use on the Event created rule for '
        . 'companies whose CRM is VinSolutions.',
    integration: IntegrationsEnum::VIN_SOLUTION,
)]
class ScheduleAppointmentFromEventActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Event $event, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $event->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        return $this->executeIntegration(
            entity: $event,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            additionalParams: $params,
            integrationOperation: function (
                Event $event,
                Apps $app,
                mixed $integrationCompany,
                array $additionalParams
            ): array {
                /** @var Lead|null $lead */
                $lead = $event->linkedResource(Lead::class);

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'event_id' => $event->getId(),
                        'error' => 'No Lead found linked to this Event',
                    ]);
                }

                try {
                    $appointmentId = new ScheduleAppointmentFromEventAction(
                        $event,
                        $lead,
                        $additionalParams
                    )->execute();
                } catch (ValidationException $e) {
                    return $this->failWorkflow([
                        'event_id' => $event->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'message' => 'Appointment scheduled successfully in VinSolution',
                    'appointment_id' => $appointmentId,
                    'event_id' => $event->getId(),
                    'lead_id' => $lead->getId(),
                ];
            },
            company: $company,
        );
    }
}
