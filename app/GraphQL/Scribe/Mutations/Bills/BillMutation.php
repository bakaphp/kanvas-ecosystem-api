<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Bills;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\MarkBillPaidAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Actions\VoidBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

class BillMutation
{
    public function create(mixed $rootValue, array $request): Bill
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateBillAction(
            data: $this->buildBillData($request['input'], $app, $company),
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

    private function buildBillData(
        array $input,
        AppInterface $app,
        CompanyInterface $company,
    ): BillData {
        $vendor = null;
        if (isset($input['vendor_organization_id'])) {
            /** @var Organization $vendor */
            $vendor = Organization::getByIdFromCompanyApp(
                (int) $input['vendor_organization_id'],
                $company,
                $app,
            );
        }

        $lines = new DataCollection(BillLineData::class, array_map(
            fn (array $line): BillLineData => new BillLineData(
                description: (string) $line['description'],
                quantity: (float) ($line['quantity'] ?? 1),
                unit_price_native: (float) $line['unit_price_native'],
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                sku: $line['sku'] ?? null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                discount_rate: isset($line['discount_rate']) ? (float) $line['discount_rate'] : null,
                discount_amount_native: (float) ($line['discount_amount_native'] ?? 0),
                tax_rate: isset($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                expense_account_id: (int) $line['expense_account_id'],
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'],
        ));

        return new BillData(
            app: $app,
            company: $company,
            vendor: $vendor,
            lines: $lines,
            currency: (string) $input['currency'],
            fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
            bill_number: $input['bill_number'] ?? null,
            net_terms_days: isset($input['net_terms_days']) ? (int) $input['net_terms_days'] : null,
            bill_date: isset($input['bill_date']) ? Carbon::parse((string) $input['bill_date']) : null,
            received_date: isset($input['received_date']) ? Carbon::parse((string) $input['received_date']) : null,
            due_date: isset($input['due_date']) ? Carbon::parse((string) $input['due_date']) : null,
            scheduled_payment_date: isset($input['scheduled_payment_date'])
                ? Carbon::parse((string) $input['scheduled_payment_date'])
                : null,
            notes: $input['notes'] ?? null,
            internal_notes: $input['internal_notes'] ?? null,
            terms: $input['terms'] ?? null,
            purchase_order_id: isset($input['purchase_order_id']) ? (int) $input['purchase_order_id'] : null,
            regional_compliance: $input['regional_compliance'] ?? null,
            tax_metadata: $input['tax_metadata'] ?? null,
            metadata: $input['metadata'] ?? null,
        );
    }
}
