<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Enums;

enum DocumentTypeEnum: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
    case QUOTE = 'quote';
    case BILL = 'bill';
    case VENDOR_CREDIT = 'vendor_credit';
    case SALES_RECEIPT = 'sales_receipt';
    case EXPENSE = 'expense';
    case JOURNAL_ENTRY = 'journal_entry';
}
