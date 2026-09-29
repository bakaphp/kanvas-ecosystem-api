<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum PriceDisclosureConfigurationEnum: string
{
    /** Company setting: the dealer is under the controlled-topic regime, so payment and add-on questions hand off. */
    case ENABLED = 'price_disclosure_enabled';
}
