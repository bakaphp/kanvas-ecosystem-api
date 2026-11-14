<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * A lead came in and is ready to be worked: its person, contacts and custom fields are saved.
 * Not dispatched for a lead written by an import that asked for no follow-on work.
 */
final class LeadCreatedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead
    ) {
    }
}
