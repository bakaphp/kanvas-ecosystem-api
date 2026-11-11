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
    case VENDOR_INVOICE = 'vendor_invoice';       // we owe vendor, unpaid → Bill
    case VENDOR_QUOTE = 'vendor_quote';           // quote from vendor (informational, no GL impact yet)
    case OUR_INVOICE = 'our_invoice';             // forwarded-back invoice we already sent (already in DB)
    case OUR_QUOTE = 'our_quote';                 // forwarded-back quote we already sent
    case BANK_STATEMENT = 'bank_statement';       // account statement → Banking statement ingest
    case REMITTANCE_ADVICE = 'remittance_advice'; // a customer telling us what they paid → AR agent, matched to invoices
    case CUSTOMER_PO = 'customer_po';             // a customer's purchase order to us → AR agent drafts invoice/quote
    case CREDIT_REQUEST = 'credit_request';       // a customer's credit application / credit form → AR agent
    case UNKNOWN = 'unknown';                     // not an accounting document
}
