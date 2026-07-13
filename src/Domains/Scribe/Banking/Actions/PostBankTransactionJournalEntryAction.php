<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\Exceptions\ExternalGlOwnershipException;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankTransactionJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\FiscalPeriodAutoOpenService;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;

/**
 * Books an UNMATCHED bank transaction to the ledger — bank fee, interest, or Suspense.
 *
 * Deliberately narrow: it will not run on a transaction that settles a document. Those take their JE from
 * the sub-ledger payment Action, and posting here too would double-count cash. The matcher calls this only
 * on the residual it couldn't explain.
 *
 * Idempotent — a transaction that already carries a journal_entry_id returns it untouched, so a webhook and
 * the safety-net poll racing on the same row can't post twice.
 *
 * @see docs/accounting/mercury-connector-plan.md §6.1
 */
class PostBankTransactionJournalEntryAction
{
    public function __construct(
        public readonly BankTransaction $bankTransaction,
        public readonly ?UserInterface $user = null,
        protected readonly BankTransactionJournalEntryComposerService $composer = new BankTransactionJournalEntryComposerService(),
        protected readonly GlOwnershipService $glOwnership = new GlOwnershipService(),
        protected readonly FiscalPeriodAutoOpenService $periodAutoOpen = new FiscalPeriodAutoOpenService(),
    ) {
    }

    public function execute(): JournalEntry
    {
        $app = $this->bankTransaction->app;
        $company = $this->bankTransaction->company;

        if (! $this->glOwnership->kanvasOwnsGl($company)) {
            $erp = $this->glOwnership->externalGlSource($company);

            throw new ExternalGlOwnershipException(
                "Refusing to post bank transaction {$this->bankTransaction->id}: company {$company->getId()} "
                . "has its general ledger owned by '{$erp?->documentSource()}', which imports its own cash "
                . 'batches as journal entries. Posting here would double-count cash.'
            );
        }

        $existing = $this->existingEntry();
        if ($existing !== null) {
            return $existing;
        }

        // Bank dates land wherever the bank says — including months nobody opened. Without this,
        // PostJournalEntryAction throws ClosedFiscalPeriodException on the first out-of-range transaction.
        $this->periodAutoOpen->ensureOpenPeriodFor(
            $app,
            $company,
            $this->bankTransaction->posted_at,
            $this->user,
        );

        return DB::connection('accounting')->transaction(function (): JournalEntry {
            $entry = new PostJournalEntryAction(
                data: $this->composer->compose($this->bankTransaction),
                postedByUser: $this->user,
            )->execute();

            $this->bankTransaction->journal_entry_id = $entry->id;
            $this->bankTransaction->save();

            $this->bankTransaction->emitLedgerEvent('accounting.bank_transaction.posted', payload: [
                'journal_entry_id' => $entry->id,
                'category' => $this->bankTransaction->category->value,
                'is_suspense' => ! $this->bankTransaction->category->isRecognized(),
                'amount_native' => $this->bankTransaction->amount_native,
            ]);

            return $entry;
        });
    }

    /**
     * A matched transaction has no JE of its own to post — it points at the settling document's entry.
     * Returning that entry (rather than throwing) keeps the caller's flow simple.
     */
    private function existingEntry(): ?JournalEntry
    {
        if ($this->bankTransaction->journal_entry_id === null) {
            return null;
        }

        return $this->bankTransaction->journalEntry;
    }
}
