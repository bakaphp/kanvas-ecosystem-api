<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Enums;

enum CustomTaxEnum: string
{
    case PRODUCT_ARANCEL_CODE = 'arancel_code';
    case PRODUCT_ARANCEL_SOURCE = 'arancel_code_source';

    case EXCHANGE_RATE = 'custom_tax_exchange_rate';
    case ITBIS_RATE = 'custom_tax_itbis_rate';
    case DEFAULT_RATE = 'custom_tax_default_rate';
    case ISC_RATES = 'custom_tax_isc_rates';
    case INCLUDE_FREIGHT_IN_CIF = 'custom_tax_include_freight_in_cif';
    case AI_REFINE_ENABLED = 'custom_tax_ai_refine_enabled';

    public const DEFAULT_EXCHANGE_RATE = 63.0;
    public const DEFAULT_ITBIS_RATE = 18.0;

    /**
     * Duty applied when the goods cannot be classified. Uses the schedule's common
     * ceiling (20%) to avoid under-charging: the shortfall comes out of LoCompro's
     * pocket once Customs liquidates the real declaration.
     */
    public const DEFAULT_FALLBACK_RATE = 20;
}
