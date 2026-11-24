<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\DataTransferObject;

use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;

final class DeliveryTarget
{
    public function __construct(
        public readonly LeadCommunicationChannelEnum $channel,
        public readonly string $destination,
    ) {
    }
}
