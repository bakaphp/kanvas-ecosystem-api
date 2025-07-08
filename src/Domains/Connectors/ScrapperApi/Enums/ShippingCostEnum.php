<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Enums;

enum ShippingCostEnum: string
{
    case DELIVERY_COST_LAST_MILE = 'delivery_cost_last_mile';
    case COURIER_COST = 'courier_cost';
    case FUEL = 'fuel';
    case CUSTOM_SERVICE = 'custom_service';
    case AIRPORT_FEE = 'airport_fee';
    case LOCAL_TRANSFER = 'local_transfer';
    case PAYMENT_FEE = 'payment_fee';
    case SERVICE_FEE = 'service_fee';
    case SHIPPING_MARGIN = 'shipping_margin';
    case LOCOMPRO_COST = 'locompro_cost';
    case CUSTOM_TAX_PROMPT = 'custom_tax_prompt';
    case CUSTOM_TAX_ENABLED = 'custom_tax_enabled';
    case CUSTOM_TAX_PDF_URL = 'custom_tax_pdf_url';
}
