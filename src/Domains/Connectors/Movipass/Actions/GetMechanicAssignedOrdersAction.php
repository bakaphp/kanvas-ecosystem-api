<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class GetMechanicAssignedOrdersAction
{
    public function __construct(
        protected readonly Users $mechanic,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): Builder
    {
        return Order::getByCustomFieldBuilder(
            CustomFieldEnum::ORDER_MECHANIC_USERS_ID->value,
            (string) $this->mechanic->getId(),
            null,
            false
        )
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', 0);
    }
}
