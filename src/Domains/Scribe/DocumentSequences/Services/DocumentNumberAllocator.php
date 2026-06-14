<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Services;

use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;

/**
 * Atomic document-number allocator.
 *
 * Generates the next sequential number for a given (apps_id, companies_id, document_type) tuple, with the
 * `SELECT … FOR UPDATE` pattern that prevents two concurrent IssueInvoiceAction calls from receiving the same
 * number (which DR DGII and every other tax authority worth respecting strictly forbids).
 *
 * Auto-creates the sequence row on first call for a given tuple (with the default prefix derived from the
 * company's accounting.invoice_number_prefix config key when present).
 *
 * @see plan §5 accounting.document_sequences schema
 */
class DocumentNumberAllocator
{
    /**
     * Allocate the next document number atomically.
     *
     * Returns the formatted string (`{prefix}{next_value}`) — e.g. "MCDR-INV-2026-0212" if the prefix is
     * "MCDR-INV-2026-" and next_value is 212.
     *
     * @param string|null $defaultPrefix Used only if the sequence row doesn't exist yet (first-time allocation
     *                                    for this company+type pair). Subsequent calls reuse the row's stored prefix.
     */
    public function allocate(
        int $appsId,
        int $companiesId,
        DocumentTypeEnum $documentType,
        ?string $defaultPrefix = null,
    ): string {
        return DB::connection('accounting')->transaction(function () use ($appsId, $companiesId, $documentType, $defaultPrefix): string {
            $sequence = $this->lockOrCreateSequence(
                $appsId,
                $companiesId,
                $documentType,
                $defaultPrefix
            );

            $allocated = $sequence->next_value;
            $sequence->next_value = $allocated + 1;
            $sequence->save();

            return $sequence->prefix . $allocated;
        });
    }

    /**
     * Peek at the next value without consuming it. For previewing in UI / draft-number generation.
     * NOT safe for actual issuance — use allocate() for that.
     */
    public function peek(int $appsId, int $companiesId, DocumentTypeEnum $documentType): ?int
    {
        $row = DocumentSequence::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('document_type', $documentType->value)
            ->first();

        return $row?->next_value;
    }

    private function lockOrCreateSequence(
        int $appsId,
        int $companiesId,
        DocumentTypeEnum $documentType,
        ?string $defaultPrefix,
    ): DocumentSequence {
        $sequence = DocumentSequence::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('document_type', $documentType->value)
            ->lockForUpdate()
            ->first();

        if ($sequence !== null) {
            return $sequence;
        }

        $sequence = new DocumentSequence();
        $sequence->apps_id = $appsId;
        $sequence->companies_id = $companiesId;
        $sequence->document_type = $documentType;
        $sequence->prefix = $defaultPrefix ?? '';
        $sequence->next_value = 1;
        $sequence->save();

        return $sequence;
    }
}
