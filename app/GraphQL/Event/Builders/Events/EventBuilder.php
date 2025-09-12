<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Event\Events\Models\Event;

class EventBuilder
{
    /**
     * Get orders for an event.
     */
    public function getOrders(mixed $root, array $args): Builder
    {
        /** @var Event $event */
        $event = $root;
        
        return $event->orders()->getQuery();
    }
}