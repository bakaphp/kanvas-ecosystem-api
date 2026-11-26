<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

class AlertUnverifiedDeliveryOrdersAction
{
    public const int MAX_HOURS_IN_VERIFYING = 24;
    public const string ALERTED_AT_FIELD = 'unverified_alerted_at';
    public const string ALERT_TEMPLATE = 'owner-order-unverified';

    public function __construct(protected AppInterface $app)
    {
    }

    public function execute(): int
    {
        $verifying = DeliveryOrderStatusEnum::VERIFYING->forApp($this->app);

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
            if ($order->get(self::ALERTED_AT_FIELD) !== null) {
                continue;
            }

            new SendOrderEmailsAction(
                $order,
                self::ALERT_TEMPLATE,
                ['hours_in_verifying' => self::MAX_HOURS_IN_VERIFYING],
            )->execute();

            $order->set(self::ALERTED_AT_FIELD, Carbon::now()->toIso8601String());
            $alerted++;
        }

        return $alerted;
    }
}
