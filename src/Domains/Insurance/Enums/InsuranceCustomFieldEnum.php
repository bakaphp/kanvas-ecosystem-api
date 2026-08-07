<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

/**
 * Shared shape every insurer maps onto, stamped on the Order so the whole graph
 * reads the same keys regardless of provider. PROVIDER is what later mutations
 * resolve the adapter from — the insurance equivalent of
 * `payment->paymentMethod->processor`.
 *
 * Anything with no cross-insurer meaning (Universal's requestId, their product
 * codes) stays on the connector's own CustomFieldEnum.
 */
enum InsuranceCustomFieldEnum: string
{
    case PROVIDER = 'insurance_provider';
    case STATUS = 'insurance_status';
    case QUOTE_NUMBER = 'insurance_quote_number';
    case POLICY_NUMBER = 'insurance_policy_number';
    case PAYMENT_URL = 'insurance_payment_url';
    case PREMIUM = 'insurance_premium';
    case TAX = 'insurance_tax';
    case TOTAL = 'insurance_total';
}
