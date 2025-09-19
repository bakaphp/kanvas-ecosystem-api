<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Souk\Orders\Models\Order;

class EventOrderBuilder
{
    /**
     * Get orders for a specific event.
     */
    public function getEventOrders(mixed $root, array $args): Builder
    {
        /** @var Event $event */
        $event = $root;
        $app = app(Apps::class);

        return Order::fromApp($app)
            ->fromCompany($event->company)
            ->where('resources_type', get_class($event))
            ->where('resources_id', $event->getId());
    }
}
