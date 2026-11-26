<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;

final class StatusMap
{
    /**
     * WaSender passes WhatsApp's own acknowledgement through, sometimes as its number and sometimes
     * by name. `sent` / 2 is the server taking the message, not the phone receiving it.
     */
    public static function toDeliveryStatus(mixed $status): ?DeliveryStatusEnum
    {
        return match (is_string($status) ? strtolower($status) : $status) {
            0, '0', 'error', 'failed' => DeliveryStatusEnum::FAILED,
            2, '2', 'sent', 'server_ack' => DeliveryStatusEnum::ACCEPTED,
            3, '3', 'delivered', 'delivery_ack' => DeliveryStatusEnum::DELIVERED,
            4, '4', 5, '5', 'read', 'played' => DeliveryStatusEnum::READ,
            default => null,
        };
    }
}
