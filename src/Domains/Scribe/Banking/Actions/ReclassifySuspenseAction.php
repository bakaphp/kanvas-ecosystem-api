<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankTransactionJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\FiscalPeriodAutoOpenService;
use RuntimeException;

/**
 * Drains one parked amount out of Suspense once we learn what it actually was.
 *
 * This is what makes Suspense a self-emptying inbox rather than a junk drawer: the bank feed parks
 * unexplained cash there, and the moment the real document shows up — someone enters the missing bill,
 * approves the auto-drafted one, or classifies the mystery deposit — the amount moves to its real account.
 *
 * Posts a mirror JE; never edits the original. A non-zero Suspense balance therefore always means "cash
 * nobody has explained yet", which is exactly the signal the dashboard and the CFO agent watch.
 *
 * @see docs/accounting/mercury-connector-plan.md §6.1
 */
class ReclassifySuspenseAction
{
    private const string RECLASS_METADATA_KEY = 'suspense_reclassified_journal_entry_id';

    public function __construct(
        public readonly BankTransaction $bankTransaction,
        public readonly Account $targetAccount,
        public readonly ?UserInterface $user = null,
        protected readonly BankTransactionJournalEntryComposerService $composer = new BankTransactionJournalEntryComposerService(),
        protected readonly FiscalPeriodAutoOpenService $periodAutoOpen = new FiscalPeriodAutoOpenService(),
    ) {
    }

    public function execute(): JournalEntry
    {
        $this->assertReclassifiable();

        $alreadyReclassified = $this->existingReclassification();
        if ($alreadyReclassified !== null) {
            return $alreadyReclassified;
        }

        $this->periodAutoOpen->ensureOpenPeriodFor(
            $this->bankTransaction->app,
            $this->bankTransaction->company,
            $this->bankTransaction->posted_at,
            $this->user,
        );

        return DB::connection('accounting')->transaction(function (): JournalEntry {
            $entry = new PostJournalEntryAction(
                data: $this->composer->composeSuspenseReclassification(
                    $this->bankTransaction,
                    $this->targetAccount,
                ),
                postedByUser: $this->user,
            )->execute();

            $metadata = $this->bankTransaction->metadata ?? [];
            $metadata[self::RECLASS_METADATA_KEY] = $entry->id;
            $metadata['suspense_reclassified_to_account_id'] = $this->targetAccount->id;
            $this->bankTransaction->metadata = $metadata;
            $this->bankTransaction->save();

            $this->bankTransaction->emitLedgerEvent('accounting.bank_transaction.suspense_reclassified', payload: [
                'journal_entry_id' => $entry->id,
                'reclassifies_journal_entry_id' => $this->bankTransaction->journal_entry_id,
                'target_account_id' => $this->targetAccount->id,
                'amount_native' => $this->bankTransaction->amount_native,
            ]);

            return $entry;
        });
    }

    private function assertReclassifiable(): void
    {
        if ($this->bankTransaction->journal_entry_id === null) {
            throw new RuntimeException(
                "BankTransaction {$this->bankTransaction->id} has no journal entry — there is nothing parked "
                . 'in Suspense to reclassify. Post it first.'
            );
        }

        if ($this->bankTransaction->category->isRecognized()) {
            throw new RuntimeException(
                "BankTransaction {$this->bankTransaction->id} was recognized as "
                . "'{$this->bankTransaction->category->value}' and booked straight to its P&L account — it "
                . 'never went to Suspense, so there is nothing to reclassify.'
            );
        }
    }

    private function existingReclassification(): ?JournalEntry
    {
        $entryId = $this->bankTransaction->metadata[self::RECLASS_METADATA_KEY] ?? null;

        if ($entryId === null) {
            return null;
        }

        return JournalEntry::query()
            ->where('apps_id', $this->bankTransaction->apps_id)
            ->where('id', (int) $entryId)
            ->first();
    }
}
