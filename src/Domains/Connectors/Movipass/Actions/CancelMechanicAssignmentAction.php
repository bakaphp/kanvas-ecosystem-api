<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
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
        return DB::connection('commerce')->transaction(function () {
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

            $assistanceCase['cancelled_mechanic_ids'] = $cancelledIds;
            $assistanceCase['mechanic'] = [];
            $assistanceCase['provider_assigned_at'] = null;
            unset($assistanceCase['mechanic_cancel']);
            $assistanceCase['pin'] = null;
            $assistanceCase['pin_hash'] = null;
            $assistanceCase['pin_generated_at'] = null;
            $assistanceCase['pin_attempt'] = null;
            $assistanceCase['pin_validated_at'] = null;
            $assistanceCase['pin_invalidated_at'] = Carbon::now()->toISOString();

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

            new NotifyAvailableMechanicsAction($order, $this->app, $this->mechanic, $cancelledIds)->execute();

            return $order->refresh();
        });
    }
}
