<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

final readonly class IssuedEcfRowImportResult
{
    public const string CREATED = 'created';
    public const string REJECTED_RECORDED = 'rejected_recorded';
    public const string SKIPPED_EXISTING = 'skipped_existing';
    public const string NEEDS_REVIEW = 'needs_review';
    public const string FAILED = 'failed';

    public function __construct(
        public string $outcome,
        public IssuedEcfExportRow $row,
        public string $message,
        public ?int $invoiceId = null,
        public ?int $fiscalDocumentId = null,
        public ?int $organizationId = null,
        public ?string $organizationAction = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'encf' => $this->row->encf,
            'row' => $this->row->rowNumber,
            'customer' => $this->row->customerName,
            'customer_tax_id' => $this->row->customerTaxId,
            'total' => $this->row->total,
            'currency' => $this->row->currency,
            'status' => $this->row->status->value,
            'invoice_id' => $this->invoiceId,
            'fiscal_document_id' => $this->fiscalDocumentId,
            'organization_id' => $this->organizationId,
            'organization' => $this->organizationAction,
            'message' => $this->message,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
