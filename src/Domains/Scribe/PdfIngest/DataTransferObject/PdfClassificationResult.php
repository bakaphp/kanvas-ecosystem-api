<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\DataTransferObject;

use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Spatie\LaravelData\Data;

/**
 * What a PdfClassifierService returns after analyzing one PDF.
 *
 * Shape comes from the LLM's structured output:
 *   - document_type + confidence + reasoning are the classification verdict
 *   - extracted is the per-field structured extraction (totals, dates, line items, NCF, etc.)
 *     — shape depends on document_type. Stored verbatim in pdf_ingest_log.extracted_payload.
 *
 * Stored fields under `extracted` (when document_type == EXPENSE_RECEIPT):
 *   - vendor_name, vendor_tax_id, vendor_email
 *   - issue_date (ISO Y-m-d), due_date (ISO Y-m-d) — due_date null for receipts (already paid)
 *   - currency (ISO 4217)
 *   - subtotal, tax, total (floats in `currency` units)
 *   - line_items: array of {description, qty, unit_price, line_total} (optional, may be empty)
 *   - tax_metadata: {ncf?, rnc?, jurisdiction?} for regional compliance
 *   - payment_method_hint: 'credit_card' | 'bank_transfer' | 'cash' | 'employee_personal' | 'unknown'
 *   - notes (free-text)
 *
 * For other document_types the extracted shape may differ — kept loose as array to avoid over-modeling.
 */
class PdfClassificationResult extends Data
{
    public function __construct(
        public readonly PdfIngestDocumentTypeEnum $document_type,
        public readonly float $confidence,
        public readonly ?string $reasoning = null,
        public readonly ?array $extracted = null,
    ) {
    }
}
