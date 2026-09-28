<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

/**
 * Only what has no cross-insurer meaning lives here. The shared shape (provider,
 * status, quote/policy number, premium/tax/total) is stamped through
 * Kanvas\Insurance\Enums\InsuranceCustomFieldEnum so the graph reads the same
 * keys whichever insurer backs the order.
 */
enum CustomFieldEnum: string
{
    case REQUEST_ID = 'universal_seguros_request_id';
    case PRODUCT = 'universal_seguros_product';
}
