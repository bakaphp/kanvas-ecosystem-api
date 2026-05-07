<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Kanvas\Event\Events\Models\Event;

class EventObserver
{
    public function updating(Event $event): void
    {
        $event->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
