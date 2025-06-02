<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Observers;

use Kanvas\ActionEngine\Engagements\Events\EngagementStatusChangedEvent;
use Kanvas\ActionEngine\Engagements\Models\Engagement;

class EngagementObserver
{
    public function updated(Engagement $engagement): void
    {
        if (! $engagement->wasChanged('message_id')) {
            return;
        }

        $message = $engagement->message; // assuming relationship exists
        if (! $message) {
            return;
        }

        EngagementStatusChangedEvent::dispatch($engagement);
    }

    public function created(Engagement $engagement): void
    {
        $message = $engagement->message;
        if (! $message) {
            return;
        }

        EngagementStatusChangedEvent::dispatch($engagement);
    }
}
