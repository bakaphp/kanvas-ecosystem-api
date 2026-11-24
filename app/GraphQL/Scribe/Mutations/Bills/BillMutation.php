<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Bills;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\MarkBillPaidAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Actions\RejectBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\Actions\VoidBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Actions\RecordBillPaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentRecordedViaEnum;
use RuntimeException;

class BillMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): Bill
    {
        $ctx = $this->actingContext();

        return new CreateBillAction(
            data: BillData::from($ctx->app, $ctx->company, $request['input']),
            user: $ctx->user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Bill
    {
        $ctx = $this->actingContext();

        return new UpdateBillAction(
            bill: $this->bill($request),
            data: BillData::from($ctx->app, $ctx->company, $request['input']),
            user: $ctx->user,
        )->execute();
    }

    public function receive(mixed $rootValue, array $request): Bill
    {
        $bill = $this->bill($request);

        return new ReceiveBillAction(
            bill: $bill,
            vendor: $this->vendorOf($bill, 'receiving'),
            user: $this->actingContext()->user,
        )->execute();
    }

    public function submitForApproval(mixed $rootValue, array $request): Bill
    {
        return new SubmitBillForApprovalAction(bill: $this->bill($request), user: $this->actingContext()->user)->execute();
    }

    public function approve(mixed $rootValue, array $request): Bill
    {
        $bill = $this->bill($request);

        return new ApproveBillAction(
            bill: $bill,
            vendor: $this->vendorOf($bill, 'approving'),
            approver: $this->actingContext()->user,
        )->execute();
    }

    public function reject(mixed $rootValue, array $request): Bill
    {
        return new RejectBillAction(
            bill: $this->bill($request),
            rejector: $this->actingContext()->user,
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function void(mixed $rootValue, array $request): Bill
    {
        return new VoidBillAction(
            bill: $this->bill($request),
            voidReasonCode: (string) $request['void_reason_code'],
            user: $this->actingContext()->user,
        )->execute();
    }

    public function markPaid(mixed $rootValue, array $request): Bill
    {
        return new MarkBillPaidAction(bill: $this->bill($request), user: $this->actingContext()->user)->execute();
    }

    public function allocatePayment(mixed $rootValue, array $request): BillPaymentAllocation
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        $bankAccount = isset($input['bank_account_id'])
            ? BankAccount::getByIdFromCompanyApp((int) $input['bank_account_id'], $ctx->company, $ctx->app)
            : null;

        // One recording path for UI, agent and provider webhook: idempotent on the reference, and left
        // unreconciled until the bank statement confirms it.
        return new RecordBillPaymentAction(
            bill: $this->bill($request),
            amountNative: (float) $input['amount_native'],
            recordedVia: PaymentRecordedViaEnum::UI,
            reference: $input['reference'] ?? null,
            paidAt: isset($input['paid_at']) ? Carbon::parse((string) $input['paid_at']) : null,
            method: isset($input['method']) ? PaymentMethodEnum::from((string) $input['method']) : PaymentMethodEnum::MANUAL,
            bankAccount: $bankAccount,
            user: $ctx->user,
            cashAccountSubType: isset($input['cash_account_sub_type'])
                ? AccountSubTypeEnum::from((string) $input['cash_account_sub_type'])
                : null,
            source: $input['source'] ?? null,
            metadata: $input['metadata'] ?? null,
        )->execute()->allocation;
    }

    private function bill(array $request): Bill
    {
        $ctx = $this->actingContext();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return $bill;
    }

    /**
     * Receive and approve both freeze the vendor snapshot, so a bill without one is refused the same way.
     */
    private function vendorOf(Bill $bill, string $step): Organization
    {
        if ($bill->vendor_organization_id === null) {
            throw new RuntimeException("Bill {$bill->id} has no vendor reference — assign a vendor before {$step}.");
        }

        $ctx = $this->actingContext();

        /** @var Organization $vendor */
        $vendor = Organization::getByIdFromCompanyApp((int) $bill->vendor_organization_id, $ctx->company, $ctx->app);

        return $vendor;
    }
}
