<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class GetMechanicPendingOrdersAction
{
    public function __construct(
        protected readonly Users $mechanic,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): Collection
    {
        return Order::fromApp($this->app)
            ->notDeleted()
            ->whereHas(
                'orderStatus',
                fn ($q) => $q->where('slug', MovipassOrderStatusEnum::AWAITING_OPERATOR->slug())
            )
            ->whereJsonContains(
                'metadata->assistance_case->notified_mechanic_ids',
                $this->mechanic->getId()
            )
            ->get();
    }
}
