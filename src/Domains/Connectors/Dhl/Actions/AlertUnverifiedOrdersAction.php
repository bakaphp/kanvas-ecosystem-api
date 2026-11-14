<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;

class AlertUnverifiedOrdersAction
{
    public const int MAX_HOURS_IN_VERIFYING = 24;
    public const string ALERT_TEMPLATE = 'owner-order-unverified';

    public function __construct(protected AppInterface $app)
    {
    }

    public function execute(): int
    {
        $verifying = OrderStatusEnum::VERIFYING->forApp($this->app);

        if ($verifying === null) {
            return 0;
        }

        $alerted = 0;

        $overdue = Order::query()
            ->fromApp($this->app)
            ->notDeleted()
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
}
