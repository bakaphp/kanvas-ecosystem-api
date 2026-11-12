<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;

final class StatusMap
{
    /**
     * Twilio's `sent` means it left Twilio for the carrier, not that it arrived — so everything up
     * to and including it is still only accepted.
     */
    public static function toDeliveryStatus(string $twilioStatus): ?DeliveryStatusEnum
    {
        return match (strtolower($twilioStatus)) {
            'accepted', 'scheduled', 'queued', 'sending', 'sent' => DeliveryStatusEnum::ACCEPTED,
            'delivered' => DeliveryStatusEnum::DELIVERED,
            'read' => DeliveryStatusEnum::READ,
            'undelivered', 'failed', 'canceled' => DeliveryStatusEnum::FAILED,
            default => null,
        };
    }
}
