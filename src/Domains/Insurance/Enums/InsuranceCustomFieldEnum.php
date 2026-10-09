<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

/**
 * Shared shape every insurer maps onto, so the whole graph reads the same keys
 * regardless of provider. Most of these are stamped on the Order; PROVIDER and
 * INSURER_COMPANY_ID also sit on the company (set at integration setup), and
 * PRODUCT_CODE is also stamped on the Order at contract time (it is what decides whether inspection is required); REQUIRES_INSPECTION is an attribute on the seeded catalog Products.
 *
 * PROVIDER is what later mutations resolve the adapter from — the insurance
 * equivalent of `payment->paymentMethod->processor`.
 *
 * Anything with no cross-insurer meaning (Universal's requestId, their internal
 * plan revisions) stays on the connector's own CustomFieldEnum.
 */
enum InsuranceCustomFieldEnum: string
{
    case PROVIDER = 'insurance_provider';
    /** The insurer's own company in Kanvas — owner of the seeded catalog Products. */
    case INSURER_COMPANY_ID = 'insurance_insurer_company_id';
    case PRODUCT_CODE = 'insurance_product_code';
    case REQUIRES_INSPECTION = 'insurance_requires_inspection';
    case STATUS = 'insurance_status';
    case QUOTE_NUMBER = 'insurance_quote_number';
    case PLAN_ID = 'insurance_plan_id';
    case POLICY_NUMBER = 'insurance_policy_number';
    /** Comma-separated filesystem ids already pushed to the insurer, so re-attaching a file never re-sends the others. */
    case UPLOADED_DOCUMENTS = 'insurance_uploaded_documents';
    /** Set once the insurer has been asked to invoice; their endpoint documents no idempotency key. */
    case INVOICED_AT = 'insurance_invoiced_at';
    case PAYMENT_URL = 'insurance_payment_url';
    case PREMIUM = 'insurance_premium';
    case RATE_PER_KM = 'insurance_rate_per_km';
    case TAX = 'insurance_tax';
    case TOTAL = 'insurance_total';
    /** What the intermediary earns on this policy, known at quote time. */
    case COMMISSION = 'insurance_commission';
    /** The insurer says this quote is ready to become a policy. */
    case CAN_EMIT = 'insurance_can_emit';
    case INSPECTION_STATUS = 'insurance_inspection_status';
}
