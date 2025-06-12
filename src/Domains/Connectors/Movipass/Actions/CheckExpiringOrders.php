<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

class CheckExpiringOrders
{
    public function __construct(
        protected AppInterface $apps,
    ) {}


    public function execute(string $appTimeZone, array $notifyIn, array $orderIds = []): Collection
    {
        return Order::fromApp($this->apps)
            ->notDeleted()
            ->whereNotFulfilled()
            ->whereNotNull('metadata')
            ->whereRaw("JSON_VALID(metadata)")
            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')) is not null")
            ->whereRaw("CONVERT_TZ(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')), ?, 'UTC') BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? MINUTE)", [$appTimeZone, $notifyIn[0]])
            ->when($orderIds, function ($query) use ($orderIds) {
                $query->whereIn('id', $orderIds);
            })
            ->orderBy('id', 'desc')
            ->get();
    }
}
