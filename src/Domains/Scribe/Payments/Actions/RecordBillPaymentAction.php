<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\DataTransferObject\RecordedBillPayment;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;

/**
 * "This bill is paid" from outside the bank feed — a person in the UI, a person telling the agent
 * ("paid Claro today"), or a payment provider's webhook. All three land here and nowhere else.
 *
 * Idempotent on the payment reference per bill: the reference becomes the allocation's idempotency key,
 * which a unique index enforces, so a webhook retry or the agent being told twice returns the first
 * payment instead of paying the bill again. Without a reference there is nothing to recognise a repeat
 * by, and every call records a payment.
 *
 * The payment stays unreconciled until a bank movement confirms it (MatchBankTransactionAction links the
 * statement row to this payment instead of posting cash a second time). One that never shows up is
 * flagged by FlagUnconfirmedBillPaymentsAction.
 */
class RecordBillPaymentAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly float $amountNative,
        public readonly PaymentRecordedViaEnum $recordedVia,
        public readonly ?string $reference = null,
        public readonly ?Carbon $paidAt = null,
        public readonly PaymentMethodEnum $method = PaymentMethodEnum::MANUAL,
        public readonly ?BankAccount $bankAccount = null,
        public readonly ?UserInterface $user = null,
        public readonly ?Filesystem $receipt = null,
        public readonly ?AccountSubTypeEnum $cashAccountSubType = null,
        public readonly ?string $source = null,
        public readonly ?array $metadata = null,
    ) {
    }

    public function execute(): RecordedBillPayment
    {
        $key = $this->idempotencyKey();
        $existing = $this->existing($key);

        if ($existing !== null) {
            return new RecordedBillPayment($existing, $existing->payment, alreadyRecorded: true);
        }

        try {
            $allocation = $this->allocate($key);
        } catch (UniqueConstraintViolationException $e) {
            // Two deliveries raced past the lookup; the loser's whole transaction (payment, allocation,
            // JE) rolled back, and the winner's row is the payment.
            $existing = $this->existing($key);

            if ($existing === null) {
                throw $e;
            }

            return new RecordedBillPayment($existing, $existing->payment, alreadyRecorded: true);
        }

        if ($this->receipt !== null) {
            $allocation->payment->addFile($this->receipt, 'receipt');
        }

        $this->bill->emitLedgerEvent('accounting.bill.payment_recorded', payload: [
            'bill_id' => $this->bill->getId(),
            'bill_number' => $this->bill->bill_number,
            'payment_id' => $allocation->payment_id,
            'amount_native' => $this->amountNative,
            'currency' => $this->bill->currency,
            'reference' => $this->reference(),
            'recorded_via' => $this->recordedVia->value,
            'has_receipt' => $this->receipt !== null,
        ]);

        return new RecordedBillPayment($allocation, $allocation->payment, alreadyRecorded: false);
    }

    private function allocate(?string $key): BillPaymentAllocation
    {
        return new AllocateBillPaymentAction(
            bill: $this->bill,
            amountNative: $this->amountNative,
            method: $this->method,
            cashAccountSubType: $this->cashAccountSubType
                ?? $this->bankAccount?->glAccount?->account_sub_type
                ?? AccountSubTypeEnum::CASH_CHECKING,
            bankAccountId: $this->bankAccount?->getId(),
            reference: $this->reference(),
            user: $this->user,
            source: $this->source ?? ($this->recordedVia === PaymentRecordedViaEnum::WEBHOOK ? 'payment_provider' : 'kanvas'),
            metadata: $this->metadata,
            paidAt: $this->paidAt,
            idempotencyKey: $key,
            recordedVia: $this->recordedVia,
        )->execute();
    }

    private function existing(?string $key): ?BillPaymentAllocation
    {
        if ($key === null) {
            return null;
        }

        return BillPaymentAllocation::query()
            ->with('payment')
            ->where('apps_id', $this->bill->apps_id)
            ->where('idempotency_key', $key)
            ->where('status', AllocationStatusEnum::ACTIVE->value)
            ->first();
    }

    private function reference(): ?string
    {
        return Str::trimToNull($this->reference);
    }

    private function idempotencyKey(): ?string
    {
        $reference = $this->reference();

        return $reference === null
            ? null
            : 'bill-payment:' . sha1($this->bill->companies_id . '|' . $this->bill->getId() . '|' . Str::lowerTrim($reference));
    }
}
