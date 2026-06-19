<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;

/**
 * Reusable JournalEntry lookups that the sub-ledger Void / Refund / Amend actions all need.
 *
 * Before this service existed, every `VoidXAction` repeated the same `JournalEntry::query()->where(...)`
 * lookup with only the `source_type` constant changing. Centralizing lets us add scope-aware behavior
 * (e.g. period-close awareness, multi-revision selection) in one place when we need it.
 */
class JournalEntryLookupService
{
    /**
     * Find the original (non-reversal, POSTED) journal entry for a given sub-ledger source row.
     * Used by every `Void*Action` to locate the JE to mirror via ReverseJournalEntryAction.
     *
     * Multiple posted JEs against the same source row are valid (a single Invoice posts ApplyPayment
     * JEs in addition to its Issue JE) — this returns the FIRST posted, non-reversal JE by id, which
     * is by construction the Issue / Receive JE.
     */
    public function findOriginalPosted(
        int $appsId,
        int $companiesId,
        string $sourceType,
        int $sourceId,
    ): ?JournalEntry {
        return JournalEntry::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->first();
    }
}
