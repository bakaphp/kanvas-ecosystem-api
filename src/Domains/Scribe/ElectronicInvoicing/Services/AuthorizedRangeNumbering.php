<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Exceptions\DocumentSequenceUnavailableException;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalNumberingInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\FiscalNumberSlot;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalRangeStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * Numbers from a range the authority authorized per document type (DR, Colombia), loaded with configure_ecf_range.
 * Inventing a sequence at 1 would hand out numbers the authority never authorized, so a missing range refuses.
 */
class AuthorizedRangeNumbering implements FiscalNumberingInterface
{
    public function __construct(
        protected readonly DocumentNumberAllocatorService $allocator = new DocumentNumberAllocatorService(),
    ) {
    }

    public function assertAvailable(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): void
    {
        if ($this->sequence($document, $type)?->isUsableRange() === true) {
            return;
        }

        throw new DocumentSequenceUnavailableException(
            "This company has no usable {$type->series()} range, so the {$type->label()} could not get a number and "
            . "nothing was drafted. Load the {$type->series()} range the tax authority authorized (configure_ecf_range) first."
        );
    }

    public function reserve(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): FiscalNumberSlot
    {
        $sequence = $this->sequence($document, $type) ?? throw new DocumentSequenceUnavailableException(
            "No authorized {$type->series()} range is configured for this company. Load it with configure_ecf_range before issuing."
        );

        return new FiscalNumberSlot(type: $type, expiresAt: $sequence->expires_at?->toDateString());
    }

    public function take(Invoice|Bill|Expense $document, FiscalNumberSlot $slot): FiscalNumberSlot
    {
        $documentType = DocumentTypeEnum::forFiscal($slot->type);
        $number = $this->allocator->allocate($document->apps_id, $document->companies_id, $documentType);

        /** @var DocumentSequence $sequence */
        $sequence = $this->allocator->find($document->apps_id, $document->companies_id, $documentType);

        if ($sequence->isExhausted() && $sequence->fiscal_number_range_id !== null) {
            FiscalNumberRange::query()
                ->whereKey($sequence->fiscal_number_range_id)
                ->update(['status' => FiscalRangeStatusEnum::EXHAUSTED->value]);
        }

        $this->noteAttention($document, $sequence);

        return $slot->withNumber($number, $sequence->fiscal_number_range_id);
    }

    private function sequence(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): ?DocumentSequence
    {
        return $this->allocator->find(
            (int) $document->apps_id,
            (int) $document->companies_id,
            DocumentTypeEnum::forFiscal($type),
        );
    }

    /**
     * Read by the AR/AP digest, which tells the admin to request a new range.
     */
    private function noteAttention(Invoice|Bill|Expense $document, DocumentSequence $sequence): void
    {
        $reasons = $sequence->attentionReasons();

        if ($reasons === []) {
            return;
        }

        try {
            $document->emitLedgerEvent(
                eventType: 'scribe.ecf_sequence.attention',
                payload: [
                    'document_sequence_id' => $sequence->getKey(),
                    'prefix' => $sequence->prefix,
                    'reasons' => $reasons,
                    'remaining' => $sequence->remaining(),
                    'used_ratio' => $sequence->usedRatio() !== null ? round($sequence->usedRatio(), 4) : null,
                    'range_end' => $sequence->range_end !== null ? $sequence->format($sequence->range_end) : null,
                    'expires_at' => $sequence->expires_at?->toDateString(),
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
