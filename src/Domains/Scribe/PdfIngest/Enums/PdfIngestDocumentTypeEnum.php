<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Enums;

/**
 * What the LLM classified the PDF as. Drives the routing decision in
 * ProcessAccountingPdfAction.
 */
enum PdfIngestDocumentTypeEnum: string
{
    case EXPENSE_RECEIPT = 'expense_receipt';     // already paid — gas, hotel, AWS, POS, SaaS receipt
    case VENDOR_INVOICE = 'vendor_invoice';       // we owe vendor, unpaid → Bill (deferred to PR 10)
    case VENDOR_QUOTE = 'vendor_quote';           // quote from vendor (informational, no GL impact yet)
    case OUR_INVOICE = 'our_invoice';             // forwarded-back invoice we already sent (already in DB)
    case OUR_QUOTE = 'our_quote';                 // forwarded-back quote we already sent
    case UNKNOWN = 'unknown';                     // not an accounting document
}
