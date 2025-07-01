<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Enums;

enum CustomFieldEnum: string
{
    case QUICKBOOKS_INVOICE_ID = 'quickbooks_invoice_id';
    case QUICKBOOKS_INVOICE_NUMBER = 'quickbooks_invoice_number';
    case QUICKBOOKS_CUSTOMER_ID = 'quickbooks_customer_id';
}
