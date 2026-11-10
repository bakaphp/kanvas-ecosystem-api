<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Connectors\Dhl\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;

class AlertUnverifiedCabinetOrdersAction
{
    public const int MAX_HOURS_IN_VERIFYING = 24;
    public const string ALERT_TEMPLATE = 'owner-cabinet-order-unverified';

    public function __construct(protected AppInterface $app)
    {
    }

    public function execute(): int
    {
        $verifying = $this->verifyingStatus();

        if ($verifying === null) {
            return 0;
        }

        $alerted = 0;

        $overdue = Order::query()
            ->fromApp($this->app)
            ->where('order_status_id', $verifying->getId())
            ->where('created_at', '<=', Carbon::now()->subHours(self::MAX_HOURS_IN_VERIFYING))
            ->get();

        foreach ($overdue as $order) {
            if ($order->get(CustomFieldEnum::UNVERIFIED_ALERTED_AT->value) !== null) {
                continue;
            }

            new SendOrderEmailsAction(
                $order,
                self::ALERT_TEMPLATE,
                ['hours_in_verifying' => self::MAX_HOURS_IN_VERIFYING],
            )->execute();

            $order->set(CustomFieldEnum::UNVERIFIED_ALERTED_AT->value, Carbon::now()->toIso8601String());
            $alerted++;
        }

        return $alerted;
    }

    private function verifyingStatus(): ?OrderStatus
    {
        $type = OrderTypes::query()
            ->where('apps_id', $this->app->getId())
            ->where('name', OrderTypeEnum::CABINET->value)
            ->first();

        return $type === null
            ? null
            : OrderStatus::query()
                ->where('order_types_id', $type->getId())
                ->where('slug', OrderStatusEnum::VERIFYING->value)
                ->first();
    }
}
