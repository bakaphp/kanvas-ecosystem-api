<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions\Concerns;

use Baka\Support\Str;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum as SequenceDocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Invoices\Models\Invoice;

trait AllocatesInvoiceNumber
{
    /**
     * A number the caller supplied (an import, an ERP) is kept; only a blank one takes the next in sequence.
     */
    protected function allocateNumberIfMissing(
        Invoice $invoice,
        SequenceDocumentTypeEnum $sequenceType,
        string $defaultPrefix,
        ?DocumentNumberAllocatorService $allocator
    ): void {
        if (Str::trimToNull($invoice->invoice_number) !== null) {
            return;
        }

        $invoice->invoice_number = ($allocator ?? new DocumentNumberAllocatorService())->allocate(
            $invoice->apps_id,
            $invoice->companies_id,
            $sequenceType,
            defaultPrefix: $defaultPrefix,
        );
    }
}
