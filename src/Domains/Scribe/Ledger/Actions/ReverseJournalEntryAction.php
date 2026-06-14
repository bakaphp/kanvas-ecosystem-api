<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Exceptions\AlreadyReversedException;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;

/**
 * Shared mirror-reversal helper used by every sub-ledger Void Action.
 *
 * What it does (atomic, single accounting-DB transaction inside PostJournalEntryAction):
 *   1. Builds mirror lines from the original JE (DR↔CR swap, all dimensions/tags preserved, memos prefixed REVERSAL)
 *   2. Posts the mirror via PostJournalEntryAction with isAdjustment=true + isReversalOf=<original.id>
 *   3. Marks the original JE status='reversed'
 *
 * What it does NOT do (caller responsibility):
 *   - Locating the original JE for the entity (caller knows source_type/source_id)
 *   - State-machine assertion on the entity being voided
 *   - Mutating the entity's own status/voided_at/etc.
 *
 * @see plan §7.7 — Reversals preserve history (mirror JEs, never row-edit)
 */
class ReverseJournalEntryAction
{
    public function __construct(
        public readonly JournalEntry $original,
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $memo,
        public readonly ?UserInterface $user = null,
        public readonly ?Carbon $postedAt = null,
        public readonly string $sourceType = '',
        public readonly ?int $sourceId = null,
    ) {
    }

    public function execute(): JournalEntry
    {
        if ($this->original->status === JournalEntryStatusEnum::REVERSED) {
            throw new AlreadyReversedException(
                "JournalEntry {$this->original->id} (je_number={$this->original->je_number}) is already reversed."
            );
        }

        $reversalData = new JournalEntryData(
            app: $this->app,
            company: $this->company,
            postedAt: $this->postedAt ?? Carbon::now(),
            sourceType: $this->sourceType !== '' ? $this->sourceType : $this->original->source_type,
            lines: new DataCollection(JournalEntryLineData::class, $this->mirrorLines()),
            sourceId: $this->sourceId ?? $this->original->source_id,
            memo: $this->memo,
            isAdjustment: true,
            isReversalOf: $this->original->id,
            source: 'kanvas',
            origin: JournalEntryOriginEnum::KANVAS,
        );

        $reversal = new PostJournalEntryAction(
            data: $reversalData,
            postedByUser: $this->user,
        )->execute();

        $this->original->status = JournalEntryStatusEnum::REVERSED;
        $this->original->save();

        return $reversal;
    }

    /**
     * @return array<int, JournalEntryLineData>
     */
    private function mirrorLines(): array
    {
        $this->original->load('lines');

        $mirrored = [];
        foreach ($this->original->lines as $i => $line) {
            $mirrored[] = new JournalEntryLineData(
                account_id: $line->account_id,
                debit_native: (float) $line->credit_native,
                credit_native: (float) $line->debit_native,
                debit_base: (float) $line->credit_base,
                credit_base: (float) $line->debit_base,
                currency: $line->currency,
                fx_rate_to_base: (float) $line->fx_rate_to_base,
                sort_order: $i,
                customer_billable_type: $line->customer_billable_type,
                customer_billable_id: $line->customer_billable_id,
                vendor_billable_type: $line->vendor_billable_type,
                vendor_billable_id: $line->vendor_billable_id,
                item_id: $line->item_id,
                class_id: $line->class_id,
                department_id: $line->department_id,
                memo: $line->memo !== null && $line->memo !== '' ? "REVERSAL — {$line->memo}" : null,
                metadata: $line->metadata,
            );
        }

        return $mirrored;
    }
}
