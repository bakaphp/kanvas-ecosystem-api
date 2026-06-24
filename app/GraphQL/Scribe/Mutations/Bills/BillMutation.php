<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Bills;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\MarkBillPaidAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\Actions\VoidBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
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

        return new AllocateBillPaymentAction(
            bill: $bill,
            amountNative: (float) $input['amount_native'],
            method: isset($input['method']) ? PaymentMethodEnum::from((string) $input['method']) : PaymentMethodEnum::MANUAL,
            cashAccountSubType: isset($input['cash_account_sub_type'])
                ? AccountSubTypeEnum::from((string) $input['cash_account_sub_type'])
                : AccountSubTypeEnum::CASH_CHECKING,
            bankAccountId: isset($input['bank_account_id']) ? (int) $input['bank_account_id'] : null,
            reference: $input['reference'] ?? null,
            user: $user,
            source: $input['source'] ?? 'kanvas',
            metadata: $input['metadata'] ?? null,
            paidAt: isset($input['paid_at']) ? Carbon::parse((string) $input['paid_at']) : null,
        )->execute();
    }
}
