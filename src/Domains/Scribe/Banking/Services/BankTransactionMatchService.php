<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Kanvas\Scribe\Banking\DataTransferObject\MatchCandidate;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Scores which open document(s) a bank movement is settling.
 *
 * Deliberately conservative. Getting this wrong marks the WRONG invoice paid, which is worse than not
 * matching at all — an unmatched transaction sits visibly in a review queue, while a mis-matched one
 * quietly corrupts AR/AP and nobody notices until a customer is chased for money they already sent.
 *
 * Three shapes of match, in the order we try them:
 *
 *   FULL     one document, amount exactly clears its balance      → the everyday case
 *   SPLIT    one payment covering several of ONE party's documents → "pay everything outstanding"
 *   PARTIAL  one document, amount reduces the balance but doesn't clear it
 *
 * ## Why partials are scored so differently
 *
 * **An exact amount is EVIDENCE. A partial amount is not.** If a bill's balance is 41,803 and a payment of
 * 41,803 arrives, the amount itself is a strong fingerprint. But if 20,000 arrives, *every* open bill with a
 * balance over 20,000 "fits" equally well — the number tells you nothing about which one it is.
 *
 * So a partial can only be trusted when there is nothing to confuse it with: the counterparty matches AND
 * that party has exactly one open document. That's what SCORE_SOLE_OPEN_DOCUMENT buys. Take either leg away
 * and it drops below the bar and goes to a human, which is the right answer — a mis-applied partial leaves
 * BOTH documents wrong.
 *
 * ## Scoring
 *
 *   amount exactly clears the balance        0.55   strong fingerprint
 *   amount merely fits (partial)             0.10   weak — it only means "not impossible"
 *   counterparty name matches the party      0.35
 *   sole open document for that counterparty 0.45   nothing else it could be
 *   dated near the document's due date       0.10
 *
 * Auto-settle needs ≥ 0.90. The combinations that clear it:
 *   FULL    + counterparty                       = 0.90  ✓
 *   PARTIAL + counterparty + sole open document  = 0.90  ✓
 *
 * And the ones that deliberately don't:
 *   FULL    alone (amount only)                  = 0.55  → two vendors bill the same round number
 *   PARTIAL + counterparty, several open docs    = 0.45  → which of their bills is this paying?
 *   PARTIAL + sole doc, no counterparty match    = 0.55  → we don't know it's even them
 *
 * ## Known limitation: a single exact match beats a possible split
 *
 * If a customer has invoices of 5,000 / 3,000 / 2,000 and pays exactly 5,000, that could be the 5,000
 * invoice — or 3,000 + 2,000. We take the single invoice, because paying one invoice in full is
 * overwhelmingly the common case and it's what QBO/Xero do. Refusing both would mean almost nothing ever
 * auto-matches, trading a rare error for a useless feature.
 *
 * The residual risk is real: if they genuinely paid the other two, we mark the wrong invoice paid. It shows
 * up in AR aging, and it's the reason the split search still refuses when the SUBSET itself is ambiguous.
 *
 * @see docs/accounting/mercury-connector-plan.md §6
 */
class BankTransactionMatchService
{
    public const float AUTO_SETTLE_THRESHOLD = 0.90;

    private const float SCORE_AMOUNT_EXACT = 0.55;
    private const float SCORE_AMOUNT_PARTIAL = 0.10;
    private const float SCORE_COUNTERPARTY = 0.35;
    private const float SCORE_SOLE_OPEN_DOCUMENT = 0.45;
    private const float SCORE_DATE_PROXIMITY = 0.10;

    /** Amounts within half a cent are the same amount. */
    private const float AMOUNT_TOLERANCE = 0.005;

    private const int DATE_PROXIMITY_DAYS = 60;

    /**
     * Subset-sum is exponential. Above this many open documents for one party we only test the "pay
     * everything outstanding" case rather than exploring 2^n combinations.
     */
    private const int MAX_DOCUMENTS_FOR_SUBSET_SEARCH = 12;

    /**
     * Open documents this transaction could be settling, best first.
     *
     * Money OUT settles a Bill (we owe); money IN settles an Invoice (we're owed). A transaction can never
     * settle both, so direction picks the sub-ledger before anything is scored.
     *
     * @return list<MatchCandidate>
     */
    public function findCandidates(BankTransaction $bankTransaction): array
    {
        $documents = $this->openDocuments($bankTransaction);

        $candidates = [];

        foreach ($documents as $document) {
            $candidate = $this->score($bankTransaction, $document, $documents);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, fn (MatchCandidate $a, MatchCandidate $b): int => $b->confidence <=> $a->confidence);

        return $candidates;
    }

    /**
     * One payment covering SEVERAL of a single party's open documents — the "pay everything outstanding"
     * wire, or a remittance clearing three invoices at once.
     *
     * Two hard constraints, both there to stop this becoming a numerology engine:
     *
     * 1. **The counterparty must match.** We never split a payment across documents from parties we can't
     *    identify. Without that anchor, subset-sum will happily find a combination of unrelated invoices
     *    from unrelated customers that happens to add up — and be confidently wrong.
     *
     * 2. **The solution must be UNIQUE.** If two different subsets both sum to the amount, we genuinely
     *    cannot tell which invoices were paid, and guessing leaves the unpicked ones wrongly outstanding.
     *
     * @return list<MatchCandidate> Empty when there's no unambiguous split.
     */
    public function findSplitCandidates(BankTransaction $bankTransaction): array
    {
        $counterparty = $bankTransaction->counterparty_name;

        if ($counterparty === null || $counterparty === '') {
            return [];
        }

        $documents = $this->openDocuments($bankTransaction)
            ->filter(fn (Bill|Invoice $d): bool => $this->counterpartyMatches($bankTransaction, $d))
            ->values();

        // A single document is the FULL/PARTIAL path's job, not a split.
        if ($documents->count() < 2) {
            return [];
        }

        $subset = $this->findUniqueSubsetSummingTo(
            array_values($documents->all()),
            $bankTransaction->amount_native,
        );

        if ($subset === null) {
            return [];
        }

        return array_map(
            fn (Bill|Invoice $document): MatchCandidate => new MatchCandidate(
                document: $document,
                // The party is confirmed and the arithmetic is exact and unique — there is nothing left to
                // be unsure about.
                confidence: 1.0,
                allocationAmount: $document->balance_due_native,
                reasons: [
                    'Part of a single payment that exactly clears ' . count($subset) . ' of this party\'s open documents',
                ],
            ),
            $subset,
        );
    }

    /**
     * Exactly one subset of $documents whose balances sum to $target, or null if there are none — or more
     * than one.
     *
     * @param list<Bill|Invoice> $documents
     * @return list<Bill|Invoice>|null
     */
    private function findUniqueSubsetSummingTo(array $documents, float $target): ?array
    {
        $total = array_sum(array_map(fn (Bill|Invoice $d): float => $d->balance_due_native, $documents));

        // The overwhelmingly common case, and the only one we can afford to check when there are many open
        // documents: the payment clears the whole outstanding balance.
        if (abs($total - $target) <= self::AMOUNT_TOLERANCE) {
            return $documents;
        }

        if (count($documents) > self::MAX_DOCUMENTS_FOR_SUBSET_SEARCH) {
            return null;
        }

        $found = [];
        $count = count($documents);

        // Enumerate every non-empty subset. Bounded above, so this is at most 4,095 iterations.
        for ($mask = 1; $mask < (1 << $count); $mask++) {
            $sum = 0.0;
            $subset = [];

            for ($i = 0; $i < $count; $i++) {
                if ($mask & (1 << $i)) {
                    $sum += $documents[$i]->balance_due_native;
                    $subset[] = $documents[$i];
                }
            }

            if (abs($sum - $target) <= self::AMOUNT_TOLERANCE) {
                $found[] = $subset;

                // Two ways to reach the same number means we can't tell which one actually happened.
                if (count($found) > 1) {
                    return null;
                }
            }
        }

        return $found[0] ?? null;
    }

    /**
     * @return Collection<int, Bill|Invoice>
     */
    private function openDocuments(BankTransaction $bankTransaction): Collection
    {
        return $bankTransaction->direction->isMoneyOut()
            ? $this->openBills($bankTransaction)
            : $this->openInvoices($bankTransaction);
    }

    /**
     * The single candidate we're willing to settle automatically, or null.
     *
     * Two guards, both necessary: the best candidate must clear the threshold, AND it must be the only one
     * that does. If two documents both look like strong matches we genuinely cannot tell them apart, and a
     * coin-flip is not a decision an accounting system gets to make.
     *
     * @param list<MatchCandidate> $candidates
     */
    public function resolveAutoSettle(array $candidates): ?MatchCandidate
    {
        $qualifying = array_values(array_filter(
            $candidates,
            fn (MatchCandidate $c): bool => $c->confidence >= self::AUTO_SETTLE_THRESHOLD,
        ));

        return count($qualifying) === 1 ? $qualifying[0] : null;
    }

    /**
     * An APPROVED Expense that already booked this exact movement.
     *
     * This is the double-count guard between the bank feed and the rest of Scribe. PDF ingest (or a human)
     * creates an Expense for a card receipt; approving it posts `DR Expense / CR Credit Card Liability`. The
     * bank feed then sees that same card charge and would post `DR Suspense / CR Credit Card Liability` —
     * crediting the card a SECOND time for one real charge. Nothing else catches this: the two entries have
     * different source types and different external ids, so they both insert cleanly.
     *
     * The tell that they're the same movement is that the Expense's credit account IS the bank account's GL
     * account. A COMPANY_CARD expense credits Credit Card Liability, which is exactly what backs the Mercury
     * credit-card bank row; a COMPANY_BANK_TRANSFER expense credits Cash — Checking, which backs the checking
     * row. Match on that, and the amount, and we're looking at one charge described twice.
     *
     * Only APPROVED expenses count. A draft has posted nothing, so there is nothing to double-count — and
     * linking to it would claim the books hold an entry they don't.
     */
    public function findBookedExpense(BankTransaction $bankTransaction): ?Expense
    {
        // An expense credits an account when money LEAVES. A deposit can never be one.
        if ($bankTransaction->direction->isMoneyIn()) {
            return null;
        }

        $bankGlSubType = $bankTransaction->bankAccount?->glAccount?->account_sub_type;

        if ($bankGlSubType === null) {
            return null;
        }

        $candidates = Expense::query()
            ->where('apps_id', $bankTransaction->apps_id)
            ->where('companies_id', $bankTransaction->companies_id)
            ->where('is_deleted', false)
            ->where('status', ExpenseStatusEnum::APPROVED->value)
            ->get();

        foreach ($candidates as $expense) {
            if ($expense->paid_by->creditAccountSubType() !== $bankGlSubType) {
                continue;
            }

            if (abs($expense->total_native - $bankTransaction->amount_native) > self::AMOUNT_TOLERANCE) {
                continue;
            }

            if (! $this->datedNear($bankTransaction, $expense->expense_date)) {
                continue;
            }

            // One expense settles one bank movement. Two identical charges to the same vendor are two
            // separate expenses, not one expense matched twice.
            if ($this->expenseAlreadyLinked($expense)) {
                continue;
            }

            return $expense;
        }

        return null;
    }

    private function expenseAlreadyLinked(Expense $expense): bool
    {
        return BankTransaction::query()
            ->where('apps_id', $expense->apps_id)
            ->where('matched_to_type', BankTransactionMatchedToTypeEnum::EXPENSE->value)
            ->where('matched_to_id', $expense->getId())
            ->exists();
    }

    /**
     * @param Collection<int, Bill|Invoice> $allOpenDocuments Needed to tell whether this party has others.
     */
    private function score(
        BankTransaction $bankTransaction,
        Bill|Invoice $document,
        Collection $allOpenDocuments,
    ): ?MatchCandidate {
        $balanceDue = $document->balance_due_native;
        $amount = $bankTransaction->amount_native;

        // Paying MORE than is owed isn't a payment against this document — it's an overpayment, and v1
        // doesn't model customer/vendor credit balances. Leave it for a human.
        if ($amount > $balanceDue + self::AMOUNT_TOLERANCE) {
            return null;
        }

        $isExact = abs($balanceDue - $amount) <= self::AMOUNT_TOLERANCE;

        $confidence = $isExact ? self::SCORE_AMOUNT_EXACT : self::SCORE_AMOUNT_PARTIAL;
        $reasons = [
            $isExact
                ? 'Amount exactly clears the outstanding balance'
                : 'Amount fits within the outstanding balance (partial payment)',
        ];

        $counterpartyMatched = $this->counterpartyMatches($bankTransaction, $document);

        if ($counterpartyMatched) {
            $confidence += self::SCORE_COUNTERPARTY;
            $reasons[] = 'Bank counterparty matches the party on the document';
        }

        // The signal that rescues a partial: if this party has only ONE open document, the payment can't be
        // against anything else of theirs. Worthless without the counterparty match, which is why the two are
        // scored separately rather than as one combined rule.
        if ($counterpartyMatched && $this->isSoleOpenDocumentForParty($bankTransaction, $document, $allOpenDocuments)) {
            $confidence += self::SCORE_SOLE_OPEN_DOCUMENT;
            $reasons[] = 'The only open document for this counterparty';
        }

        if ($this->datedNearDueDate($bankTransaction, $document)) {
            $confidence += self::SCORE_DATE_PROXIMITY;
            $reasons[] = 'Dated within ' . self::DATE_PROXIMITY_DAYS . ' days of the due date';
        }

        return new MatchCandidate(
            document: $document,
            confidence: min($confidence, 1.0),
            allocationAmount: $amount,
            reasons: $reasons,
        );
    }

    /**
     * @param Collection<int, Bill|Invoice> $allOpenDocuments
     */
    private function isSoleOpenDocumentForParty(
        BankTransaction $bankTransaction,
        Bill|Invoice $document,
        Collection $allOpenDocuments,
    ): bool {
        $siblings = $allOpenDocuments->filter(
            fn (Bill|Invoice $other): bool => $other->getId() !== $document->getId()
                && $this->counterpartyMatches($bankTransaction, $other),
        );

        return $siblings->isEmpty();
    }

    /**
     * The bank gives us a free-text counterparty name; the document points at a Guild Organization. Compare
     * the normalized forms in both directions, because "AWS" on the statement and "Amazon Web Services LLC"
     * on the bill are the same vendor and neither string contains the other outright.
     */
    private function counterpartyMatches(BankTransaction $bankTransaction, Bill|Invoice $document): bool
    {
        $counterparty = $bankTransaction->counterparty_name;

        if ($counterparty === null || $counterparty === '') {
            return false;
        }

        $organization = $this->partyFor($document);

        if ($organization === null) {
            return false;
        }

        $bankName = $this->normalize($counterparty);
        $partyName = $this->normalize($organization->name);

        if ($bankName === '' || $partyName === '') {
            return false;
        }

        return str_contains($partyName, $bankName) || str_contains($bankName, $partyName);
    }

    private function datedNearDueDate(BankTransaction $bankTransaction, Bill|Invoice $document): bool
    {
        return $this->datedNear($bankTransaction, $document->due_date);
    }

    private function datedNear(BankTransaction $bankTransaction, ?Carbon $reference): bool
    {
        if ($reference === null) {
            return false;
        }

        return abs($reference->diffInDays($bankTransaction->transaction_date)) <= self::DATE_PROXIMITY_DAYS;
    }

    private function partyFor(Bill|Invoice $document): ?Organization
    {
        $organizationId = $document instanceof Bill
            ? $document->vendor_organization_id
            : $document->customer_organization_id;

        if ($organizationId === null) {
            return null;
        }

        return Organization::query()->where('id', $organizationId)->first();
    }

    private function normalize(string $name): string
    {
        return strtolower(trim(OrganizationNameNormalizerService::normalize($name) ?: $name));
    }

    /**
     * @return Collection<int, Bill>
     */
    private function openBills(BankTransaction $bankTransaction): Collection
    {
        return Bill::query()
            ->where('apps_id', $bankTransaction->apps_id)
            ->where('companies_id', $bankTransaction->companies_id)
            ->where('is_deleted', false)
            ->where('document_status', BillDocumentStatusEnum::RECEIVED->value)
            ->where('balance_due_native', '>', 0)
            ->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function openInvoices(BankTransaction $bankTransaction): Collection
    {
        return Invoice::query()
            ->where('apps_id', $bankTransaction->apps_id)
            ->where('companies_id', $bankTransaction->companies_id)
            ->where('is_deleted', false)
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED->value,
                InvoiceDocumentStatusEnum::SENT->value,
            ])
            ->where('balance_due_native', '>', 0)
            ->get();
    }
}
