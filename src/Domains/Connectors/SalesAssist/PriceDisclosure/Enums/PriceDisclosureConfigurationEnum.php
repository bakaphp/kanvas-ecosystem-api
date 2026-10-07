<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum PriceDisclosureConfigurationEnum: string
{
    /** Company custom field: turns the vehicle_price_disclosure tool on for the dealer. Off, the tool is a no-op. */
    case ENABLED = 'price_disclosure_enabled';

    /**
     * Company custom field: JSON object of the dealer-required fees in dollars, keyed by PriceDisclosureFeeEnum,
     * e.g. {"documentation_fee": 85, "electronic_filing_charge": 33}. A missing key counts as 0.
     */
    case FEES = 'price_disclosure_fees';

    /** Lead custom field: vehicle_key → the disclosure already rendered for it, so it is not repeated. */
    case DISCLOSURES = 'price_disclosures';
}
