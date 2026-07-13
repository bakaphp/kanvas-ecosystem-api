<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\DataTransferObject\MatchCandidate;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedByEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankTransactionMatchService;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\FiscalPeriodAutoOpenService;
use Kanvas\Scribe\Payments\Actions\CreateScribePaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;

/**
 * Decides what one bank movement means, and books it accordingly. This is where "the invoice shows as paid"
 * actually happens.
 *
 * The decision tree, in order:
 *
 *   1. Already settled or posted            → nothing to do (safe to re-run; webhook and poll both call this)
 *   2. Bank fee / interest                  → book straight to P&L. Nothing to match; we know what it is.
 *   3. An internal transfer                 → Suspense. Real cash, but it settles no document.
 *   4. An approved Expense already booked it → link the two and post NOTHING. The sub-ledger got there first.
 *   5. Exactly one confident candidate      → SETTLE it. Payment + allocation + the sub-ledger's own cash JE.
 *                                             Clears the document, or reduces it if the amount is partial.
 *   6. A set of ONE party's documents that
 *      sums exactly to the amount           → SETTLE them all against a single Payment (the "pay everything
 *                                             outstanding" wire). Tried second: if one document matches
 *                                             exactly, that's the simpler explanation and it wins.
 *   7. Anything else                        → Suspense + the review queue.
 *
 * **The cash is never a draft.** The bank says the money moved, so it posts immediately and permanently.
 * What Suspense holds is not uncertain cash — it's *classified cash with an unclassified counterpart*. The
 * only open question on an unmatched row is "which account does the other side belong to?", and answering it
 * (ReclassifySuspenseAction) drains Suspense. That's the whole review step.
 *
 * We deliberately do NOT fabricate a Bill or an Invoice for unmatched money. A Bill exists to track what you
 * owe BEFORE you pay it; once the cash has left there is no payable, and minting one invents a liability that
 * doesn't exist only to mark it paid a second later. Same for an Invoice on the money-in side. Bills and
 * invoices are created from the counterparty's document, ahead of the cash — and then this matcher settles
 * them (case 4). That is the only direction that relationship runs.
 *
 * The invariant that holds the design together: **a transaction the sub-ledger already booked never gets its
 * own bank JE.** Whether the sub-ledger went first (case 4, an approved Expense) or we drove it (case 5, a
 * settled bill/invoice), that movement is on the books exactly once. A second entry would double-count the
 * cash, and dedupe cannot catch it — the two entries carry different source types and different external
 * ids, so they both insert cleanly. Cases 4/5 and cases 2/3/6 are mutually exclusive by construction: link
 * OR settle OR post, never two of them.
 *
 * @see docs/accounting/mercury-connector-plan.md §6 + §6.1
 */
class MatchBankTransactionAction
{
    public function __construct(
        public readonly BankTransaction $bankTransaction,
        public readonly ?UserInterface $user = null,
        protected readonly BankTransactionMatchService $matcher = new BankTransactionMatchService(),
        protected readonly FiscalPeriodAutoOpenService $periodAutoOpen = new FiscalPeriodAutoOpenService(),
    ) {
    }

    public function execute(): BankTransactionMatchOutcomeEnum
    {
        if ($this->bankTransaction->isAccountedFor()) {
            return BankTransactionMatchOutcomeEnum::ALREADY_ACCOUNTED;
        }

        if ($this->bankTransaction->category->isRecognized()) {
            $this->post();

            return BankTransactionMatchOutcomeEnum::RECOGNIZED;
        }

        if ($this->bankTransaction->category === BankTransactionCategoryEnum::TRANSFER) {
            $this->post();

            return BankTransactionMatchOutcomeEnum::REVIEW;
        }

        // Before ANY posting: was this movement already booked by an approved Expense? PDF ingest turns a
        // card receipt into an Expense, and approving it credits the card liability. Posting here too would
        // credit it a second time for one real charge.
        $bookedExpense = $this->matcher->findBookedExpense($this->bankTransaction);
        if ($bookedExpense !== null) {
            $this->linkToBookedExpense($bookedExpense);

            return BankTransactionMatchOutcomeEnum::ALREADY_BOOKED;
        }

        $candidates = $this->matcher->findCandidates($this->bankTransaction);
        $winner = $this->matcher->resolveAutoSettle($candidates);

        if ($winner !== null) {
            $this->settle([$winner]);

            return $winner->isPartial()
                ? BankTransactionMatchOutcomeEnum::SETTLED_PARTIAL
                : BankTransactionMatchOutcomeEnum::SETTLED;
        }

        // Only once no single document explains the payment: does a set of this party's documents? Tried
        // second on purpose — if one invoice matches exactly, that's the simpler explanation and we take it
        // rather than hunting for a combination that happens to add up to the same number.
        $split = $this->matcher->findSplitCandidates($this->bankTransaction);

        if ($split !== []) {
            $this->settle($split);

            return BankTransactionMatchOutcomeEnum::SETTLED_SPLIT;
        }

        $this->stashCandidates($candidates);

        // Cash posts either way — the bank moved it, and that is not in question. The only difference is
        // whether we hand the reviewer a shortlist to choose from or a blank field.
        $this->post();

        return $candidates !== []
            ? BankTransactionMatchOutcomeEnum::AMBIGUOUS
            : BankTransactionMatchOutcomeEnum::REVIEW;
    }

    /**
     * Books the movement against the document(s) it settles.
     *
     * ONE bank transaction is ONE Payment, however many documents it covers. That's the whole reason the
     * Payment is created up front and handed to each allocation rather than letting each one synthesize its
     * own: three Payments for one wire would triple-count the cash, and the allocation tables exist precisely
     * to map one payment across N documents.
     *
     * Each allocation posts its own share of the cash JE (DR AP / CR Cash for its slice), and the shares sum
     * to the transaction — cash leaves once, in the right total.
     *
     * @param list<MatchCandidate> $candidates One for a full/partial match, several for a split.
     */
    private function settle(array $candidates): void
    {
        $paidAt = $this->bankTransaction->posted_at;
        $isBill = $candidates[0]->isBill();

        // The sub-ledger posts its JE at the payment date, which is a real bank date and may land in a month
        // nobody opened. Same reason PostBankTransactionJournalEntryAction does this.
        $this->periodAutoOpen->ensureOpenPeriodFor(
            $this->bankTransaction->app,
            $this->bankTransaction->company,
            $paidAt,
            $this->user,
        );

        DB::connection('accounting')->transaction(function () use ($candidates, $paidAt, $isBill): void {
            $payment = $this->createPayment($isBill, $paidAt);

            foreach ($candidates as $candidate) {
                $this->allocate($candidate, $payment, $paidAt);
            }

            $this->bankTransaction->match_status = BankTransactionMatchStatusEnum::AUTO_MATCHED;
            $this->bankTransaction->matched_to_type = $isBill
                ? BankTransactionMatchedToTypeEnum::BILL_PAYMENT
                : BankTransactionMatchedToTypeEnum::INVOICE_PAYMENT;
            $this->bankTransaction->matched_to_id = $payment->getId();
            $this->bankTransaction->matched_at = Carbon::now();
            $this->bankTransaction->matched_by = BankTransactionMatchedByEnum::SYSTEM;
            $this->bankTransaction->match_confidence = $candidates[0]->confidence;
            // Point at the sub-ledger's JE rather than posting our own — this IS the no-double-post rule.
            $this->bankTransaction->journal_entry_id = $this->paymentJournalEntryId(
                $isBill ? 'bill_payment' : 'payment',
                $payment->getId(),
            );

            // A split covers several documents, and matched_to_id can only hold the Payment. The breakdown
            // lives here so a reviewer can see exactly what this one wire cleared.
            $metadata = $this->bankTransaction->metadata ?? [];
            $metadata['settled_documents'] = array_map(
                fn (MatchCandidate $c): array => $c->toArray(),
                $candidates,
            );
            $this->bankTransaction->metadata = $metadata;
            $this->bankTransaction->save();

            $this->bankTransaction->emitLedgerEvent('accounting.bank_transaction.matched', payload: [
                'matched_to_type' => $this->bankTransaction->matched_to_type->value,
                'payment_id' => $payment->getId(),
                'document_count' => count($candidates),
                'documents' => array_map(fn (MatchCandidate $c): array => [
                    'id' => $c->document->getId(),
                    'number' => $c->documentNumber(),
                    'allocated' => round($c->allocationAmount, 2),
                    'partial' => $c->isPartial(),
                ], $candidates),
                'confidence' => round($candidates[0]->confidence, 4),
                'amount_native' => $this->bankTransaction->amount_native,
            ]);
        });
    }

    /**
     * The single Payment representing this one bank movement. Created up front so every allocation attaches
     * to the SAME payment — see the note on settle().
     */
    private function createPayment(bool $isBill, Carbon $paidAt): Payment
    {
        return new CreateScribePaymentAction(
            app: $this->bankTransaction->app,
            company: $this->bankTransaction->company,
            amountNative: $this->bankTransaction->amount_native,
            currency: $this->bankTransaction->currency,
            direction: $isBill
                ? PaymentDirectionEnum::OUTBOUND
                : PaymentDirectionEnum::INBOUND,
            method: PaymentMethodEnum::MERCURY_MATCH,
            user: $this->user,
            fxRateToBase: $this->bankTransaction->fx_rate_to_base,
            paymentDate: $paidAt,
            bankAccountId: $this->bankTransaction->bank_account_id,
            reference: $this->bankTransaction->external_id,
            notes: 'Matched from bank feed — ' . ($this->bankTransaction->counterparty_name ?? 'unknown counterparty'),
            source: $this->bankTransaction->source,
            metadata: ['bank_transaction_id' => $this->bankTransaction->id],
        )->execute();
    }

    /**
     * Applies one document's share of the payment. The sub-ledger Action writes the allocation row, posts the
     * cash JE for that share, and recomputes the document's balance — flipping it to PAID only when the
     * balance actually reaches zero, so a partial correctly leaves the document open.
     */
    private function allocate(MatchCandidate $candidate, Payment $payment, Carbon $paidAt): void
    {
        $metadata = [
            'bank_transaction_id' => $this->bankTransaction->id,
            'match_confidence' => round($candidate->confidence, 4),
            'match_reasons' => $candidate->reasons,
        ];

        $document = $candidate->document;

        if ($document instanceof Bill) {
            new AllocateBillPaymentAction(
                bill: $document,
                amountNative: $candidate->allocationAmount,
                method: PaymentMethodEnum::MERCURY_MATCH,
                cashAccountSubType: $this->cashAccountSubType(),
                payment: $payment,
                bankAccountId: $this->bankTransaction->bank_account_id,
                reference: $this->bankTransaction->external_id,
                user: $this->user,
                source: $this->bankTransaction->source,
                metadata: $metadata,
                paidAt: $paidAt,
            )->execute();

            return;
        }

        new AllocateInvoicePaymentAction(
            invoice: $document,
            amountNative: $candidate->allocationAmount,
            method: PaymentMethodEnum::MERCURY_MATCH,
            cashAccountSubType: $this->cashAccountSubType(),
            payment: $payment,
            bankAccountId: $this->bankTransaction->bank_account_id,
            reference: $this->bankTransaction->external_id,
            user: $this->user,
            source: $this->bankTransaction->source,
            metadata: $metadata,
            paidAt: $paidAt,
        )->execute();
    }

    /**
     * Records that this movement is the Expense's, and posts nothing.
     *
     * The Expense's approval JE already booked the charge — same cash, same contra account. We point the bank
     * row at that entry so the two are visibly one thing, and stop. This is the same no-double-post rule as
     * the settle path, just with the sub-ledger having gone first.
     */
    private function linkToBookedExpense(Expense $expense): void
    {
        $this->bankTransaction->match_status = BankTransactionMatchStatusEnum::AUTO_MATCHED;
        $this->bankTransaction->matched_to_type = BankTransactionMatchedToTypeEnum::EXPENSE;
        $this->bankTransaction->matched_to_id = $expense->getId();
        $this->bankTransaction->matched_at = Carbon::now();
        $this->bankTransaction->matched_by = BankTransactionMatchedByEnum::SYSTEM;
        $this->bankTransaction->journal_entry_id = $this->expenseJournalEntryId($expense);
        $this->bankTransaction->save();

        $this->bankTransaction->emitLedgerEvent('accounting.bank_transaction.matched', payload: [
            'matched_to_type' => BankTransactionMatchedToTypeEnum::EXPENSE->value,
            'expense_id' => $expense->getId(),
            'expense_number' => $expense->expense_number,
            'amount_native' => $this->bankTransaction->amount_native,
            'already_booked' => true,
        ]);
    }

    private function expenseJournalEntryId(Expense $expense): ?int
    {
        return JournalEntry::query()
            ->where('apps_id', $this->bankTransaction->apps_id)
            ->where('companies_id', $this->bankTransaction->companies_id)
            ->where('source_type', 'expense')
            ->where('source_id', $expense->getId())
            ->latest('id')
            ->first()?->id;
    }

    private function post(): void
    {
        new PostBankTransactionJournalEntryAction(
            bankTransaction: $this->bankTransaction,
            user: $this->user,
        )->execute();
    }

    /**
     * The bank account carries its own GL cash account, which is the one that actually moved. Derive the
     * sub-type from it rather than assuming CASH_CHECKING — a tenant with a savings account and a checking
     * account would otherwise have both post to checking.
     */
    private function cashAccountSubType(): AccountSubTypeEnum
    {
        // Account casts account_sub_type to the enum already — it arrives typed, not as a string.
        return $this->bankTransaction->bankAccount?->glAccount?->account_sub_type
            ?? AccountSubTypeEnum::CASH_CHECKING;
    }

    private function paymentJournalEntryId(string $sourceType, int $paymentId): ?int
    {
        $entry = JournalEntry::query()
            ->where('apps_id', $this->bankTransaction->apps_id)
            ->where('companies_id', $this->bankTransaction->companies_id)
            ->where('source_type', $sourceType)
            ->where('source_id', $paymentId)
            ->latest('id')
            ->first();

        return $entry?->id;
    }

    /**
     * @param list<MatchCandidate> $candidates
     */
    private function stashCandidates(array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $metadata = $this->bankTransaction->metadata ?? [];
        $metadata['match_candidates'] = array_map(
            fn (MatchCandidate $c): array => $c->toArray(),
            array_slice($candidates, 0, 5),
        );

        $this->bankTransaction->metadata = $metadata;
        $this->bankTransaction->save();
    }
}
