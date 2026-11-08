<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Enums;

/**
 * Terminal-ish status of a PDF ingest attempt. `pending` is the initial state before classification
 * finishes; everything else is the outcome of routing.
 *
 *   pending                   → just received, not yet processed (extractor hasn't run)
 *   entity_created            → routed to a Scribe entity (Expense draft, or a Bill submitted for approval)
 *   needs_review              → a Bill was created but left DRAFT: vendor or GL could not be resolved
 *                                (the reason is in rejected_reason and on the bill's metadata.ingest)
 *   awaiting_bill_support     → classified as vendor_invoice; Bill sub-ledger lands in PR 10. Backfill auto-runs then.
 *   quote_inbound_logged      → classified as vendor_quote; informational, no entity
 *   ignored_our_doc           → it's one of OUR docs forwarded back (already in DB)
 *   ignored_duplicate         → PR 9.1 dedup hit — same PDF content (Path 2) or same vendor+bill_number
 *                                (Path 3) already produced an entity. linked_entity_* points at the
 *                                pre-existing one.
 *   routed_to_ar              → a customer document (remittance, PO, credit request) handed to the AR agent
 *   rejected_unknown          → not an accounting doc; ingest rejected with reason
 *   failed                    → extractor errored, classification failed, attach failed, etc.
 */
enum PdfIngestStatusEnum: string
{
    case PENDING = 'pending';
    case ENTITY_CREATED = 'entity_created';
    case NEEDS_REVIEW = 'needs_review';
    case AWAITING_BILL_SUPPORT = 'awaiting_bill_support';
    case QUOTE_INBOUND_LOGGED = 'quote_inbound_logged';
    case IGNORED_OUR_DOC = 'ignored_our_doc';
    case IGNORED_DUPLICATE = 'ignored_duplicate';
    case ROUTED_TO_AR = 'routed_to_ar';
    case REJECTED_UNKNOWN = 'rejected_unknown';
    case FAILED = 'failed';
}
