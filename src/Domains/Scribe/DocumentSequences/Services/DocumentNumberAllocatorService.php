<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Exceptions\DocumentSequenceUnavailableException;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalRangeStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;

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
class DocumentNumberAllocatorService
{
    /**
     * Allocate the next document number atomically.
     *
     * Returns the formatted string (`{prefix}{next_value}`) — e.g. "MCDR-INV-2026-212" if the prefix is
     * "MCDR-INV-2026-" and next_value is 212, or "E310000000004" for a 10-digit-padded e-CF range.
     *
     * Throws DocumentSequenceUnavailableException, before consuming anything, when the row's range is
     * used up or expired, or when an e-CF type has no range configured at all.
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

            $this->assertCanAllocate($sequence);

            $allocated = $sequence->next_value;
            $sequence->next_value = $allocated + 1;
            $sequence->save();

            return $sequence->format($allocated);
        });
    }

    /**
     * Peek at the next value without consuming it. For previewing in UI / draft-number generation.
     * NOT safe for actual issuance — use allocate() for that.
     */
    public function peek(int $appsId, int $companiesId, DocumentTypeEnum $documentType): ?int
    {
        return $this->find($appsId, $companiesId, $documentType)?->next_value;
    }

    public function find(int $appsId, int $companiesId, DocumentTypeEnum $documentType): ?DocumentSequence
    {
        /** @var DocumentSequence|null $row */
        $row = DocumentSequence::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('document_type', $documentType->value)
            ->first();

        return $row;
    }

    /**
     * Points a sequence at a newly authorized range. The counter never moves backwards: numbers already
     * handed out (accepted, rejected or burned) stay used even when the new range overlaps them.
     *
     * An e-CF range is also recorded as a `fiscal_number_ranges` row that supersedes the previous one; this
     * row keeps only the cursor and points at it, so every range ever authorized stays accountable.
     */
    public function configureRange(
        int $appsId,
        int $companiesId,
        DocumentTypeEnum $documentType,
        string $prefix,
        int $rangeStart,
        int $rangeEnd,
        ?Carbon $expiresAt,
        ?int $padding,
        ?int $firstUnused = null,
        ?string $country = null,
    ): DocumentSequence {
        if ($rangeEnd < $rangeStart) {
            throw new DocumentSequenceUnavailableException("Range end {$rangeEnd} is before range start {$rangeStart}.");
        }

        return DB::connection('accounting')->transaction(function () use (
            $appsId,
            $companiesId,
            $documentType,
            $prefix,
            $rangeStart,
            $rangeEnd,
            $expiresAt,
            $padding,
            $firstUnused,
            $country
        ): DocumentSequence {
            /** @var DocumentSequence|null $sequence */
            $sequence = DocumentSequence::query()
                ->where('apps_id', $appsId)
                ->where('companies_id', $companiesId)
                ->where('document_type', $documentType->value)
                ->lockForUpdate()
                ->first();

            $sequence ??= new DocumentSequence([
                'apps_id' => $appsId,
                'companies_id' => $companiesId,
                'document_type' => $documentType,
                'next_value' => $rangeStart,
            ]);

            $nextValue = max($rangeStart, $firstUnused ?? 0, (int) $sequence->next_value);

            if ($nextValue > $rangeEnd) {
                throw new DocumentSequenceUnavailableException(
                    "Every number up to {$rangeEnd} is already used for {$documentType->value}; this range has nothing left to issue."
                );
            }

            $sequence->prefix = $prefix;
            $sequence->next_value = $nextValue;
            $sequence->range_start = $rangeStart;
            $sequence->range_end = $rangeEnd;
            $sequence->expires_at = $expiresAt;
            $sequence->padding = $padding;

            if ($documentType->fiscalCode() !== null) {
                $sequence->fiscal_number_range_id = $this->recordFiscalRange($sequence, $documentType, $country)->getKey();
            }

            $sequence->save();

            return $sequence;
        });
    }

    private function recordFiscalRange(
        DocumentSequence $sequence,
        DocumentTypeEnum $documentType,
        ?string $country
    ): FiscalNumberRange {
        $company = Companies::find($sequence->companies_id);
        $environment = ($company !== null ? EcfEnvironmentEnum::forCompany($company) : EcfEnvironmentEnum::TEST)->value;
        $code = (string) $documentType->fiscalCode();
        $country ??= ($company !== null ? FiscalRegimes::forCompany($company)?->country() : null)
            ?? throw new DocumentSequenceUnavailableException(
                "Company {$sequence->companies_id} has no fiscal regime (no e-invoicing provider and no country), so the range has no authority."
            );

        FiscalNumberRange::query()
            ->where('companies_id', $sequence->companies_id)
            ->where('environment', $environment)
            ->where('country', $country)
            ->where('document_type', $code)
            ->where('status', FiscalRangeStatusEnum::ACTIVE->value)
            ->update(['status' => FiscalRangeStatusEnum::SUPERSEDED->value]);

        return FiscalNumberRange::create([
            'apps_id' => $sequence->apps_id,
            'companies_id' => $sequence->companies_id,
            'environment' => $environment,
            'country' => $country,
            'document_type' => $code,
            'series' => $sequence->prefix,
            'range_from' => (int) $sequence->range_start,
            'range_to' => (int) $sequence->range_end,
            'padding' => $sequence->padding,
            'authorized_at' => Carbon::now(),
            'expires_at' => $sequence->expires_at,
            'status' => FiscalRangeStatusEnum::ACTIVE,
        ]);
    }

    private function assertCanAllocate(DocumentSequence $sequence): void
    {
        $label = $sequence->prefix !== '' ? $sequence->prefix : $sequence->document_type->value;

        if ($sequence->isExpired()) {
            throw new DocumentSequenceUnavailableException(
                "The {$label} number range expired on {$sequence->expires_at->toDateString()}. Request a new range and configure it before issuing."
            );
        }

        if ($sequence->isExhausted()) {
            throw new DocumentSequenceUnavailableException(
                "The {$label} number range is used up (last number {$sequence->format((int) $sequence->range_end)}). Request a new range and configure it before issuing."
            );
        }
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

        if ($documentType->requiresConfiguredRange()) {
            throw new DocumentSequenceUnavailableException(
                "No authorized number range is configured for {$documentType->value}. Load it with configure_ecf_range before issuing."
            );
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
