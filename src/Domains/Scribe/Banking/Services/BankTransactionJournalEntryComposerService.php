<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

/**
 * JE composer for bank transactions that settle NOTHING on the books.
 *
 * The no-double-posting invariant: a bank transaction matched to an invoice or bill takes its accounting
 * from the sub-ledger payment Action (which posts DR AP / CR Cash or DR Cash / CR AR itself). Composing a
 * second JE here for the same movement would double-count cash. So this composer runs ONLY for unmatched
 * transactions, and PostBankTransactionJournalEntryAction enforces that.
 *
 * Two shapes, per §6.1:
 *
 *   Recognized (bank fee, interest income) — we know what it is, so book it straight to P&L:
 *     money out:  DR Bank Fees        / CR Cash
 *     money in:   DR Cash             / CR Interest Income
 *
 *   Unknown — cash is real but unexplained, so park it in Suspense rather than guess:
 *     money out:  DR Suspense         / CR Cash
 *     money in:   DR Cash             / CR Suspense
 *
 * Suspense self-drains: ReclassifySuspenseAction moves the amount to the real account the moment the
 * matching document appears. A non-zero Suspense balance is therefore a work queue, not an error.
 *
 * Cash comes from the BankAccount's own gl_account_id — NOT AccountResolverService::bySubType(CASH_CHECKING).
 * A company can hold several bank accounts, each backed by its own GL cash account, and only the row knows
 * which one moved.
 *
 * @see docs/accounting/mercury-connector-plan.md §6.1
 */
class BankTransactionJournalEntryComposerService
{
    public function __construct(
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function compose(BankTransaction $bankTransaction): JournalEntryData
    {
        $bankTransaction->loadMissing('bankAccount');

        $app = $bankTransaction->app;
        $company = $bankTransaction->company;

        $cashAccountId = $this->resolveCashAccountId($bankTransaction);
        $contraAccount = $this->resolveContraAccount($bankTransaction);

        $amountNative = $bankTransaction->amount_native;
        $amountBase = $bankTransaction->amount_base;
        $currency = $bankTransaction->currency;
        $fxRate = $bankTransaction->fx_rate_to_base;

        $memo = $this->composeMemo($bankTransaction, $contraAccount);

        $cashLine = fn (bool $isDebit, int $sortOrder): JournalEntryLineData => new JournalEntryLineData(
            account_id: $cashAccountId,
            debit_native: $isDebit ? $amountNative : 0.0,
            credit_native: $isDebit ? 0.0 : $amountNative,
            debit_base: $isDebit ? $amountBase : 0.0,
            credit_base: $isDebit ? 0.0 : $amountBase,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: $sortOrder,
            memo: $memo,
        );

        $contraLine = fn (bool $isDebit, int $sortOrder): JournalEntryLineData => new JournalEntryLineData(
            account_id: $contraAccount->id,
            debit_native: $isDebit ? $amountNative : 0.0,
            credit_native: $isDebit ? 0.0 : $amountNative,
            debit_base: $isDebit ? $amountBase : 0.0,
            credit_base: $isDebit ? 0.0 : $amountBase,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: $sortOrder,
            memo: $memo,
        );

        $lines = $bankTransaction->direction->isMoneyOut()
            ? [$contraLine(true, 0), $cashLine(false, 1)]
            : [$cashLine(true, 0), $contraLine(false, 1)];

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $bankTransaction->posted_at,
            sourceType: 'bank_transaction',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $bankTransaction->id,
            memo: $memo,
            source: $bankTransaction->source,
            // Namespaced so a bank-feed JE can never collide with another JE the same connector imports
            // under the unique (apps_id, source, external_id).
            externalId: $bankTransaction->external_id !== null
                ? 'bank_txn-' . $bankTransaction->external_id
                : null,
            // The movement happened at the bank, not in Kanvas — EXTERNAL keeps outbound sync listeners
            // from pushing it back into whatever accounting connector is configured.
            origin: JournalEntryOriginEnum::EXTERNAL,
            metadata: [
                'bank_transaction_id' => $bankTransaction->id,
                'bank_account_id' => $bankTransaction->bank_account_id,
                'category' => $bankTransaction->category->value,
                'is_suspense' => ! $bankTransaction->category->isRecognized(),
                'counterparty_name' => $bankTransaction->counterparty_name,
            ],
        );
    }

    /**
     * Moves a parked amount out of Suspense and into the account we now know it belongs to. Cash is NOT
     * touched — the original JE already booked it correctly; only the contra side was a placeholder.
     *
     *   money out:  original DR Suspense / CR Cash   →  reclass DR {target} / CR Suspense
     *   money in:   original DR Cash / CR Suspense   →  reclass DR Suspense / CR {target}
     *
     * A mirror JE, never an edit of the original — history is preserved per the GL invariants.
     */
    public function composeSuspenseReclassification(
        BankTransaction $bankTransaction,
        Account $targetAccount,
    ): JournalEntryData {
        $app = $bankTransaction->app;
        $company = $bankTransaction->company;

        $suspenseAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SUSPENSE);

        $amountNative = $bankTransaction->amount_native;
        $amountBase = $bankTransaction->amount_base;
        $currency = $bankTransaction->currency;
        $fxRate = $bankTransaction->fx_rate_to_base;

        $counterparty = $bankTransaction->counterparty_name ?? 'Unknown counterparty';
        $memo = "Reclassify from Suspense to {$targetAccount->name} — {$counterparty}";

        // Money out parked a DEBIT in Suspense, so clearing it means CREDITing Suspense and debiting the
        // real account. Money in is the mirror.
        $suspenseIsCredited = $bankTransaction->direction->isMoneyOut();

        $suspenseLine = new JournalEntryLineData(
            account_id: $suspenseAccount->id,
            debit_native: $suspenseIsCredited ? 0.0 : $amountNative,
            credit_native: $suspenseIsCredited ? $amountNative : 0.0,
            debit_base: $suspenseIsCredited ? 0.0 : $amountBase,
            credit_base: $suspenseIsCredited ? $amountBase : 0.0,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: 1,
            memo: $memo,
        );

        $targetLine = new JournalEntryLineData(
            account_id: $targetAccount->id,
            debit_native: $suspenseIsCredited ? $amountNative : 0.0,
            credit_native: $suspenseIsCredited ? 0.0 : $amountNative,
            debit_base: $suspenseIsCredited ? $amountBase : 0.0,
            credit_base: $suspenseIsCredited ? 0.0 : $amountBase,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: 0,
            memo: $memo,
        );

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $bankTransaction->posted_at,
            sourceType: 'bank_transaction',
            lines: new DataCollection(JournalEntryLineData::class, [$targetLine, $suspenseLine]),
            sourceId: $bankTransaction->id,
            memo: $memo,
            source: $bankTransaction->source,
            externalId: $bankTransaction->external_id !== null
                ? 'bank_txn_reclass-' . $bankTransaction->external_id
                : null,
            origin: JournalEntryOriginEnum::EXTERNAL,
            metadata: [
                'bank_transaction_id' => $bankTransaction->id,
                'reclassifies_journal_entry_id' => $bankTransaction->journal_entry_id,
                'target_account_id' => $targetAccount->id,
                'is_suspense_reclassification' => true,
            ],
        );
    }

    private function resolveCashAccountId(BankTransaction $bankTransaction): int
    {
        $bankAccount = $bankTransaction->bankAccount;

        if ($bankAccount === null) {
            throw new RuntimeException(
                "BankTransaction {$bankTransaction->id} has no bank_account — cannot resolve the cash GL account."
            );
        }

        return (int) $bankAccount->gl_account_id;
    }

    private function resolveContraAccount(BankTransaction $bankTransaction): Account
    {
        $subType = $bankTransaction->category->contraAccountSubType() ?? AccountSubTypeEnum::SUSPENSE;

        return $this->accountResolver->bySubType(
            $bankTransaction->app,
            $bankTransaction->company,
            $subType,
        );
    }

    private function composeMemo(BankTransaction $bankTransaction, Account $contraAccount): string
    {
        $counterparty = $bankTransaction->counterparty_name ?? 'Unknown counterparty';

        return $bankTransaction->category->isRecognized()
            ? "Bank {$bankTransaction->category->value} — {$counterparty}"
            : "Unclassified bank movement — {$counterparty} (parked in {$contraAccount->name})";
    }
}
