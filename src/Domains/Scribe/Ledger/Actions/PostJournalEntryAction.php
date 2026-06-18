<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Scribe\Ledger\Services\JournalEntryValidatorService;
use Kanvas\Scribe\Ledger\Services\PeriodCloseService;

/**
 * THE single entry point for every JE posting in Scribe.
 *
 * Every sub-ledger Action (Invoice, Bill, Expense, SalesReceipt, Transfer, Payment, etc.) ends with a call to this
 * Action. A coverage test enforces it. Direct writes to the journal_entries / journal_entry_lines tables outside
 * of this Action are not allowed.
 *
 * Flow:
 *   1. PeriodCloseService.assertCanPostAt → throws ClosedFiscalPeriodException if the period rejects.
 *   2. JournalEntryValidatorService.validate → throws InvalidJournalEntryLineException / UnbalancedJournalEntryException
 *      on shape or balance violations.
 *   3. Single DB transaction: insert journal_entries header + journal_entry_lines rows.
 *   4. Return the persisted JournalEntry with lines hydrated.
 *
 * Caller responsibility:
 *   - Compose the lines as JournalEntryLineData DTOs (per-sub-ledger Composer services do this in PRs 2+).
 *   - Provide correct fx_rate_to_base on each line for non-base-currency entries.
 *   - Provide the dimensional tags (customer_billable_*, vendor_billable_*, item_id) when applicable.
 *
 * @see plan §3.5 sub-ledger → GL auto-posting table
 * @see plan §7.7 GL invariants
 */
class PostJournalEntryAction
{
    public function __construct(
        public readonly JournalEntryData $data,
        public readonly ?UserInterface $postedByUser = null,
        protected readonly JournalEntryValidatorService $validator = new JournalEntryValidatorService(),
        protected readonly PeriodCloseService $periodCloseService = new PeriodCloseService(),
    ) {
    }

    public function execute(): JournalEntry
    {
        $appsId = $this->data->app->getId();
        $companiesId = $this->data->company->getId();

        $period = $this->periodCloseService->assertCanPostAt(
            $appsId,
            $companiesId,
            $this->data->postedAt,
            $this->postedByUser,
        );

        $linesArray = $this->linesToArray();
        $this->validator->validate($linesArray);

        return DB::connection('accounting')->transaction(function () use ($appsId, $companiesId, $period, $linesArray): JournalEntry {
            $entry = new JournalEntry();
            $entry->apps_id = $appsId;
            $entry->companies_id = $companiesId;
            $entry->je_number = $this->data->jeNumber;
            $entry->posted_at = $this->data->postedAt;
            $entry->fiscal_period_id = $period->id;
            $entry->source_type = $this->data->sourceType;
            $entry->source_id = $this->data->sourceId;
            $entry->source_external_id = $this->data->sourceExternalId;
            $entry->memo = $this->data->memo;
            $entry->is_adjustment = $this->data->isAdjustment;
            $entry->is_reversal_of = $this->data->isReversalOf;
            $entry->posted_by_users_id = $this->postedByUser?->getId();
            $entry->status = JournalEntryStatusEnum::POSTED;
            $entry->source = $this->data->source;
            $entry->external_id = $this->data->externalId;
            $entry->origin = $this->data->origin;
            $entry->metadata = $this->data->metadata;
            $entry->save();

            foreach ($linesArray as $index => $line) {
                $lineRow = new JournalEntryLine();
                $lineRow->journal_entry_id = $entry->id;
                $lineRow->account_id = $line['account_id'];
                $lineRow->sort_order = (int) ($line['sort_order'] ?? $index);
                $lineRow->debit_native = $line['debit_native'];
                $lineRow->credit_native = $line['credit_native'];
                $lineRow->debit_base = $line['debit_base'];
                $lineRow->credit_base = $line['credit_base'];
                $lineRow->currency = $line['currency'];
                $lineRow->fx_rate_to_base = $line['fx_rate_to_base'];
                $lineRow->customer_billable_type = $line['customer_billable_type'] ?? null;
                $lineRow->customer_billable_id = $line['customer_billable_id'] ?? null;
                $lineRow->vendor_billable_type = $line['vendor_billable_type'] ?? null;
                $lineRow->vendor_billable_id = $line['vendor_billable_id'] ?? null;
                $lineRow->item_id = $line['item_id'] ?? null;
                $lineRow->class_id = $line['class_id'] ?? null;
                $lineRow->department_id = $line['department_id'] ?? null;
                $lineRow->memo = $line['memo'] ?? null;
                $lineRow->metadata = $line['metadata'] ?? null;
                $lineRow->save();
            }

            return $entry->fresh(['lines']);
        });
    }

    /**
     * Convert the DataCollection of JournalEntryLineData to the array shape the validator expects.
     * Single source of truth on what each line looks like inside this Action.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linesToArray(): array
    {
        return $this->data->lines->toCollection()
            ->map(function (JournalEntryLineData $line): array {
                return [
                    'account_id' => $line->account_id,
                    'sort_order' => $line->sort_order,
                    'debit_native' => $line->debit_native,
                    'credit_native' => $line->credit_native,
                    'debit_base' => $line->debit_base,
                    'credit_base' => $line->credit_base,
                    'currency' => $line->currency,
                    'fx_rate_to_base' => $line->fx_rate_to_base,
                    'customer_billable_type' => $line->customer_billable_type,
                    'customer_billable_id' => $line->customer_billable_id,
                    'vendor_billable_type' => $line->vendor_billable_type,
                    'vendor_billable_id' => $line->vendor_billable_id,
                    'item_id' => $line->item_id,
                    'class_id' => $line->class_id,
                    'department_id' => $line->department_id,
                    'memo' => $line->memo,
                    'metadata' => $line->metadata,
                ];
            })
            ->all();
    }
}
