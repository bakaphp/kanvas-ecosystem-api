<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Contracts\PayeeInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedByEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use RuntimeException;

/**
 * Links a bill to cash that ALREADY left the bank, and drains the Suspense entry holding it.
 *
 * The out-of-order case: the bank feed saw the money go out before anyone entered the vendor's invoice, so
 * the cash was parked in Suspense. The bill is entered later (by hand, or from a PDF), and now the two need
 * to meet. This is the only correct way to book that — the everyday path is simply ReclassifySuspenseAction
 * (pick an expense account), because a payment with no payable behind it needs no Bill at all.
 *
 * The accounting, and why it is NOT just "receive the bill and pay it":
 *
 *   already posted   DR Suspense   / CR Cash        (the bank feed — cash is ALREADY gone)
 *   1. receive bill  DR Expense    / CR AP
 *   2. settle        DR AP         / CR Suspense
 *   ────────────────────────────────────────────
 *   net              Expense +X, Cash −X, AP 0, Suspense 0
 *
 * The naive path — ReceiveBill then AllocateBillPayment — would post DR AP / CR Cash in step 2 and credit
 * cash a SECOND time for one real payment. That's why the allocation runs with postCashJournalEntry: false
 * and the cash side is cleared against Suspense instead. Suspense is what stands in for the cash that
 * already moved.
 *
 * @see docs/accounting/mercury-connector-plan.md §6.1
 */
class SettleBillFromSuspenseAction
{
    public function __construct(
        public readonly BankTransaction $bankTransaction,
        public readonly Bill $bill,
        public readonly PayeeInterface $vendor,
        public readonly ?UserInterface $user = null,
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function execute(): Bill
    {
        $this->assertSettleable();

        return DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;

            if ($bill->document_status === BillDocumentStatusEnum::DRAFT) {
                $bill = new ReceiveBillAction(
                    bill: $bill,
                    vendor: $this->vendor,
                    user: $this->user,
                )->execute();
            }

            $allocation = new AllocateBillPaymentAction(
                bill: $bill,
                amountNative: $this->bankTransaction->amount_native,
                method: PaymentMethodEnum::MERCURY_MATCH,
                bankAccountId: $this->bankTransaction->bank_account_id,
                reference: $this->bankTransaction->external_id,
                user: $this->user,
                source: $this->bankTransaction->source,
                metadata: [
                    'bank_transaction_id' => $this->bankTransaction->id,
                    'settled_from_suspense' => true,
                ],
                paidAt: $this->bankTransaction->posted_at,
                // The bank feed already credited cash. See the class docblock.
                postCashJournalEntry: false,
            )->execute();

            // The cash side: clear AP against the Suspense balance the bank feed parked.
            new ReclassifySuspenseAction(
                bankTransaction: $this->bankTransaction,
                targetAccount: $this->accountResolver->bySubType(
                    $this->bankTransaction->app,
                    $this->bankTransaction->company,
                    AccountSubTypeEnum::ACCOUNTS_PAYABLE,
                ),
                user: $this->user,
            )->execute();

            $this->bankTransaction->match_status = BankTransactionMatchStatusEnum::MANUALLY_MATCHED;
            $this->bankTransaction->matched_to_type = BankTransactionMatchedToTypeEnum::BILL_PAYMENT;
            $this->bankTransaction->matched_to_id = (int) $allocation->payment_id;
            $this->bankTransaction->matched_at = Carbon::now();
            $this->bankTransaction->matched_by = BankTransactionMatchedByEnum::HUMAN;
            $this->bankTransaction->save();

            $this->bankTransaction->emitLedgerEvent('accounting.bank_transaction.settled_from_suspense', payload: [
                'bill_id' => $bill->getId(),
                'bill_number' => $bill->bill_number,
                'payment_id' => (int) $allocation->payment_id,
                'amount_native' => $this->bankTransaction->amount_native,
            ]);

            return $bill->refresh();
        });
    }

    private function assertSettleable(): void
    {
        if ($this->bankTransaction->journal_entry_id === null) {
            throw new RuntimeException(
                "BankTransaction {$this->bankTransaction->id} has nothing parked in Suspense — there is no "
                . 'cash entry to settle this bill against. Post it first.'
            );
        }

        if ($this->bankTransaction->match_status->isSettled()) {
            throw new RuntimeException(
                "BankTransaction {$this->bankTransaction->id} is already matched to "
                . "{$this->bankTransaction->matched_to_type?->value}. Settling it again would pay the bill twice."
            );
        }

        if ($this->bankTransaction->direction->isMoneyIn()) {
            throw new RuntimeException(
                "BankTransaction {$this->bankTransaction->id} is money IN — it cannot settle a bill."
            );
        }
    }
}
