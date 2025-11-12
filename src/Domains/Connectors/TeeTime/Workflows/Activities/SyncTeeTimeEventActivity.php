<?php

namespace Kanvas\Connectors\TeeTime\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\TeeTime\Enums\EventStatusEnum;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncTeeTimeEventActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $event, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $event,
            app: $app,
            integration: IntegrationsEnum::TEE_TIME,
            additionalParams: $params,
            integrationOperation: function ($event, $app, $integrationCompany, $additionalParams) use ($params) {
                $eventName = $additionalParams['currentEventTypeName'] ?? null;

                if ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    $toStatus = $params['to_status'] ?? null;

                    if ($toStatus === EventStatusEnum::ACTIVE->value) {
                        $eventVersion = $event->versions->first();
                        $participants = $eventVersion->participants;

                        if ($eventVersion && ! $participants->isEmpty()) {
                            $codes = (new CreatePassAction(
                                $eventVersion->event,
                                $eventVersion
                            ))->forAllParticipants();

                            new SendEventEmailsAction($eventVersion, EmailTemplateEnum::BOOKING_CREATED->value, [
                                'codes' => $codes,
                            ])->execute();
                        }
                    }
                }

                return [
                    'event' => $event->getId(),
                    'status' => 'success',
                    'event_name' => $eventName,
                    'message' => 'Event synced correctly',
                    'data' => $event->toArray(),
                    'response' => $event->toArray(),
                ];
            },
            company: $event->company,
        );
    }
}
