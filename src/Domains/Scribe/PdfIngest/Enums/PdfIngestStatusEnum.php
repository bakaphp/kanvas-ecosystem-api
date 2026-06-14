<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Enums;

/**
 * Terminal-ish status of a PDF ingest attempt. `pending` is the initial state before classification
 * finishes; everything else is the outcome of routing.
 *
 *   pending                   → just received, not yet processed (extractor hasn't run)
 *   entity_created            → routed to a Scribe entity (Expense draft created)
 *   awaiting_bill_support     → classified as vendor_invoice; Bill sub-ledger lands in PR 10. Backfill auto-runs then.
 *   quote_inbound_logged      → classified as vendor_quote; informational, no entity
 *   ignored_our_doc           → it's one of OUR docs forwarded back (already in DB)
 *   rejected_unknown          → not an accounting doc; ingest rejected with reason
 *   failed                    → extractor errored, classification failed, attach failed, etc.
 */
enum PdfIngestStatusEnum: string
{
    case PENDING = 'pending';
    case ENTITY_CREATED = 'entity_created';
    case AWAITING_BILL_SUPPORT = 'awaiting_bill_support';
    case QUOTE_INBOUND_LOGGED = 'quote_inbound_logged';
    case IGNORED_OUR_DOC = 'ignored_our_doc';
    case REJECTED_UNKNOWN = 'rejected_unknown';
    case FAILED = 'failed';
}
