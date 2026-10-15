<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceCustomFieldEnum: string
{
    case PROVIDER = 'insurance_provider';
    case INSURER_COMPANY_ID = 'insurance_insurer_company_id';
    case PRODUCT_CODE = 'insurance_product_code';
    case REQUIRES_INSPECTION = 'insurance_requires_inspection';
    case STATUS = 'insurance_status';
    case QUOTE_NUMBER = 'insurance_quote_number';
    case PLAN_ID = 'insurance_plan_id';
    case POLICY_NUMBER = 'insurance_policy_number';
    case UPLOADED_DOCUMENTS = 'insurance_uploaded_documents';
    case INVOICED_AT = 'insurance_invoiced_at';
    case PAYMENT_URL = 'insurance_payment_url';
    case PREMIUM = 'insurance_premium';
    case RATE_PER_KM = 'insurance_rate_per_km';
    case TAX = 'insurance_tax';
    case TOTAL = 'insurance_total';
    case COMMISSION = 'insurance_commission';
    case CAN_EMIT = 'insurance_can_emit';
    case INSPECTION_STATUS = 'insurance_inspection_status';
    case ORDER_ID = 'insurance_order_id';
    case LEAD_ID = 'insurance_lead_id';
    case QUOTE_SIGNATURE = 'insurance_quote_signature';
    case FAILURE_REASON = 'insurance_failure_reason';
}
