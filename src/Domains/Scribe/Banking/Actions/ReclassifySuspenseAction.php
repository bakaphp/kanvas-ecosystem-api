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
    public function __construct(
        public readonly BankTransaction $bankTransaction,
        public readonly Account $targetAccount,
        public readonly ?UserInterface $user = null,
        protected readonly BankTransactionJournalEntryComposerService $composer = new BankTransactionJournalEntryComposerService(),
        protected readonly FiscalPeriodAutoOpenService $periodAutoOpen = new FiscalPeriodAutoOpenService(),
    ) {
    }

    /**
     * The "already reclassified?" check reads the row under lockForUpdate in the transaction that writes
     * it, so two callers holding the same transaction cannot both post a reclassification.
     */
    public function execute(): JournalEntry
    {
        $this->assertReclassifiable();

        $alreadyReclassified = $this->existingReclassification($this->bankTransaction);
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
            /** @var BankTransaction $locked */
            $locked = BankTransaction::query()->whereKey($this->bankTransaction->getKey())->lockForUpdate()->firstOrFail();

            $existing = $this->existingReclassification($locked);
            if ($existing !== null) {
                $this->bankTransaction->setRawAttributes($locked->getAttributes(), true);

                return $existing;
            }

            $entry = new PostJournalEntryAction(
                data: $this->composer->composeSuspenseReclassification(
                    $locked,
                    $this->targetAccount,
                ),
                postedByUser: $this->user,
            )->execute();

            $locked->suspense_reclassified_journal_entry_id = $entry->id;
            $locked->suspense_reclassified_to_account_id = $this->targetAccount->id;
            $locked->save();
            $this->bankTransaction->setRawAttributes($locked->getAttributes(), true);

            $locked->emitLedgerEvent('accounting.bank_transaction.suspense_reclassified', payload: [
                'journal_entry_id' => $entry->id,
                'reclassifies_journal_entry_id' => $locked->journal_entry_id,
                'target_account_id' => $this->targetAccount->id,
                'amount_native' => $locked->amount_native,
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

    private function existingReclassification(BankTransaction $transaction): ?JournalEntry
    {
        if ($transaction->suspense_reclassified_journal_entry_id === null) {
            return null;
        }

        return JournalEntry::query()
            ->where('apps_id', $transaction->apps_id)
            ->where('id', $transaction->suspense_reclassified_journal_entry_id)
            ->first();
    }
}
