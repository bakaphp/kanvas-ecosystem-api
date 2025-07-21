<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Enums;

enum CustomFieldEnum: string
{
    case QUICKBOOKS_INVOICE_ID = 'quickbooks_invoice_id';
    case QUICKBOOKS_INVOICE_NUMBER = 'quickbooks_invoice_number';
    case QUICKBOOKS_CUSTOMER_ID = 'quickbooks_customer_id';

    // New deposit-related fields
    case QUICKBOOKS_DEPOSIT_ID = 'quickbooks_deposit_id';
    case QUICKBOOKS_DEPOSIT_AMOUNT = 'quickbooks_deposit_amount';
    case QUICKBOOKS_DEPOSIT_APPLIED_AMOUNT = 'quickbooks_deposit_applied_amount';
    case QUICKBOOKS_CREDIT_BALANCE = 'quickbooks_credit_balance';
}
