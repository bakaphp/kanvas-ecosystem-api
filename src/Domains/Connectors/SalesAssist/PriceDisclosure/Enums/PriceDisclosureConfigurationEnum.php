<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum PriceDisclosureConfigurationEnum: string
{
    /** Company setting: turns the vehicle_price_disclosure tool on for the dealer. Off, the tool is a no-op. */
    case ENABLED = 'price_disclosure_enabled';

    /** Company setting: the dealer-required fee in dollars added on top of the stored vehicle price. */
    case DEALER_FEE = 'price_disclosure_dealer_fee';

    /** Lead custom field: vehicle_key → the disclosure already rendered for it, so it is not repeated. */
    case DISCLOSURES = 'price_disclosures';
}
