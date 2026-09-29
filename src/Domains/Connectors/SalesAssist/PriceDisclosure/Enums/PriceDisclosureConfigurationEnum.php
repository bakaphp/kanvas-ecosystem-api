<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum PriceDisclosureConfigurationEnum: string
{
    /** Company setting: turns the vehicle_price_disclosure tool on for the dealer. Off, the tool is a no-op. */
    case ENABLED = 'price_disclosure_enabled';
}
