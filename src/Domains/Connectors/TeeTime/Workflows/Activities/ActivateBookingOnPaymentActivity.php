<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TeeTime\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class ActivateBookingOnPaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::TEE_TIME,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $eventName = $additionalParams['currentEventTypeName'] ?? null;

                if ($eventName !== WorkflowEnum::AFTER_PAYMENT_INTENT->value) {
                    return $this->skipped($order, $eventName, 'Ignored: not an after-payment-intent event');
                }

                // The booking order is linked to the Event via the `resources` morph.
                $event = $order->resource;

                if (! $event instanceof Event) {
                    return $this->skipped($order, $eventName, 'Ignored: order is not linked to an event');
                }

                $activeStatus = EventStatus::firstOrCreate([
                    'companies_id' => $event->companies_id,
                    'apps_id' => $event->apps_id,
                    'name' => EventStatusEnum::ACTIVE->value,
                ], [
                    'users_id' => $event->users_id,
                    'slug' => strtolower(Str::slug(EventStatusEnum::ACTIVE->value)),
                ]);

                $event->update(['event_status_id' => $activeStatus->getId()]);

                $eventVersion = $event->versions->first();
                $codes = [];

                if ($eventVersion && ! $eventVersion->participants->isEmpty()) {
                    $codes = new CreatePassAction(
                        $eventVersion->event,
                        $eventVersion
                    )->forAllParticipants();

                    new SendEventEmailsAction($eventVersion, EmailTemplateEnum::BOOKING_CREATED->value, [
                        'codes' => $codes,
                    ])->execute();
                }

                return [
                    'order' => $order->getId(),
                    'event' => $event->getId(),
                    'status' => 'success',
                    'event_name' => $eventName,
                    'codes' => count($codes),
                    'message' => 'Booking activated and passes generated',
                    'response' => $event->toArray(),
                ];
            },
            company: $order->company,
        );
    }

    private function skipped(Model $order, ?string $eventName, string $message): array
    {
        return [
            'order' => $order->getId(),
            'status' => 'skipped',
            'event_name' => $eventName,
            'message' => $message,
            'response' => null,
        ];
    }
}
