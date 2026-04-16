<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Jobs\RetryNotifyMechanicsJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CancelMechanicAssignmentAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly Users $mechanic,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): Order
    {
        $order = DB::connection('commerce')->transaction(function () {
            $order = Order::where('id', $this->order->getId())
                ->lockForUpdate()
                ->firstOrFail();

            $nonCancellableStatuses = [
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ];

            if (in_array($order->orderStatus?->slug, $nonCancellableStatuses, true)) {
                throw new ValidationException('Order cannot be cancelled in its current status');
            }

            $metadata = $order->metadata ?? [];
            $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

            $assignedMechanicId = $assistanceCase['mechanic']['user_id'] ?? null;

            if ((int) $assignedMechanicId !== $this->mechanic->getId()) {
                throw new ValidationException('Mechanic is not assigned to this order');
            }

            $cancelledIds = $assistanceCase['cancelled_mechanic_ids'] ?? [];
            $cancelledIds[] = $this->mechanic->getId();
            $cancelledIds = array_values(array_unique($cancelledIds));

            RefreshActiveAssistanceEvent::dispatch($order, $this->mechanic->getId());

            $assistanceCase['cancelled_mechanic_ids'] = $cancelledIds;
            $assistanceCase['mechanic'] = [];
            $assistanceCase['notified_mechanic_ids'] = [];
            $assistanceCase['status'] = MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug();
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
            $assistanceCase['provider_assigned_at'] = null;
            $assistanceCase['pin'] = null;
            $assistanceCase['pin_hash'] = null;
            $assistanceCase['pin_generated_at'] = null;
            $assistanceCase['pin_attempt'] = null;
            $assistanceCase['pin_validated_at'] = null;
            $assistanceCase['pin_invalidated_at'] = Carbon::now()->toISOString();
            unset($assistanceCase['mechanic_cancel']);

            $order->metadata = [
                ...$metadata,
                'assistance_case' => $assistanceCase,
                'data' => [
                    ...($metadata['data'] ?? []),
                    'assistance_case' => $assistanceCase,
                ],
            ];
            $order->saveQuietly();
            $order->set(CustomFieldEnum::ORDER_MECHANIC_USERS_ID->value, null);
            $order->set(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN->value, null);
            $order->set(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN_HASH->value, null);

            $order->transitionToStatus(
                $this->mechanic,
                MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug(),
            );

            return $order->refresh();
        });

        try {
            $cancelledIds = $order->metadata['assistance_case']['cancelled_mechanic_ids'] ?? [];
            new NotifyAvailableMechanicsAction($order, $this->app, $this->mechanic, $cancelledIds)->execute();
        } catch (ValidationException) {
            // No mechanics available right now — schedule retries with a delay.
            RetryNotifyMechanicsJob::dispatch(
                $order,
                $this->mechanic,
                $cancelledIds,
                attempt: 1,
                maxAttempts: 3,
                retryDelayMinutes: 1,
            )->delay(now()->addMinutes(1));
        }

        return $order->refresh();
    }
}
