<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum CustomFieldEnum: string
{
    case REQUEST_ID = 'universal_seguros_request_id';
    case QUOTE_NUMBER = 'universal_seguros_quote_number';
    case POLICY_NUMBER = 'universal_seguros_policy_number';
    case PRODUCT = 'universal_seguros_product';
    case STATUS = 'universal_seguros_status';
    case PRIMA = 'universal_seguros_prima';
    case IMPUESTO = 'universal_seguros_impuesto';
    case TOTAL_COBRO = 'universal_seguros_total_cobro';
    case PAYMENT_URL = 'universal_seguros_payment_url';
}
