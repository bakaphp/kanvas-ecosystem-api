<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Bills;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
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
    public function create(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateBillAction(
            data: BillData::from($app, $company, $request['input']),
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateBillAction(
            bill: $bill,
            data: BillData::from($app, $company, $request['input']),
            user: $user,
        )->execute();
    }

    public function receive(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($bill->vendor_organization_id === null) {
            throw new RuntimeException(
                "Bill {$bill->id} has no vendor reference — assign a vendor before receiving."
            );
        }

        /** @var Organization $vendor */
        $vendor = Organization::getByIdFromCompanyApp(
            (int) $bill->vendor_organization_id,
            $company,
            $app,
        );

        return new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: $user,
        )->execute();
    }

    public function submitForApproval(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new SubmitBillForApprovalAction(bill: $bill, user: $user)->execute();
    }

    public function approve(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($bill->vendor_organization_id === null) {
            throw new RuntimeException(
                "Bill {$bill->id} has no vendor reference — assign a vendor before approving."
            );
        }

        /** @var Organization $vendor */
        $vendor = Organization::getByIdFromCompanyApp(
            (int) $bill->vendor_organization_id,
            $company,
            $app,
        );

        return new ApproveBillAction(
            bill: $bill,
            vendor: $vendor,
            approver: $user
        )->execute();
    }

    public function reject(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new RejectBillAction(
            bill: $bill,
            rejector: $user,
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function void(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new VoidBillAction(
            bill: $bill,
            voidReasonCode: (string) $request['void_reason_code'],
            user: $user,
        )->execute();
    }

    public function markPaid(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new MarkBillPaidAction(bill: $bill, user: $user)->execute();
    }

    public function allocatePayment(mixed $rootValue, array $request): BillPaymentAllocation
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Bill $bill */
        $bill = Bill::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $bankAccount = isset($input['bank_account_id'])
            ? BankAccount::getByIdFromCompanyApp((int) $input['bank_account_id'], $company, $app)
            : null;

        // One recording path for UI, agent and provider webhook: idempotent on the reference, and left
        // unreconciled until the bank statement confirms it.
        return new RecordBillPaymentAction(
            bill: $bill,
            amountNative: (float) $input['amount_native'],
            recordedVia: PaymentRecordedViaEnum::UI,
            reference: $input['reference'] ?? null,
            paidAt: isset($input['paid_at']) ? Carbon::parse((string) $input['paid_at']) : null,
            method: isset($input['method']) ? PaymentMethodEnum::from((string) $input['method']) : PaymentMethodEnum::MANUAL,
            bankAccount: $bankAccount,
            user: $user,
            cashAccountSubType: isset($input['cash_account_sub_type'])
                ? AccountSubTypeEnum::from((string) $input['cash_account_sub_type'])
                : null,
            source: $input['source'] ?? null,
            metadata: $input['metadata'] ?? null,
        )->execute()->allocation;
    }
}
