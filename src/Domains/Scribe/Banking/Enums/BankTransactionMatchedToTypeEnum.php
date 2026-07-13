<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

enum BankTransactionMatchedToTypeEnum: string
{
    case INVOICE_PAYMENT = 'invoice_payment';
    case BILL_PAYMENT = 'bill_payment';
    case EXPENSE = 'expense';
    case TRANSFER = 'transfer';
    case SALES_RECEIPT = 'sales_receipt';
}
