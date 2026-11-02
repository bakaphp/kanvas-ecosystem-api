<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;

final class StatusMap
{
    /**
     * Meta's `sent` means it left Meta for the device, not that it arrived.
     */
    public static function toDeliveryStatus(string $metaStatus): ?DeliveryStatusEnum
    {
        return match (strtolower($metaStatus)) {
            'sent' => DeliveryStatusEnum::ACCEPTED,
            'delivered' => DeliveryStatusEnum::DELIVERED,
            'read' => DeliveryStatusEnum::READ,
            'failed' => DeliveryStatusEnum::FAILED,
            default => null,
        };
    }
}
