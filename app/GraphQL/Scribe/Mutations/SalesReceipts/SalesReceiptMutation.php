<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\SalesReceipts;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\SalesReceipts\Actions\CreateSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\Actions\VoidSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceipt as SalesReceiptData;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptLine as SalesReceiptLineData;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

class SalesReceiptMutation
{
    public function create(mixed $rootValue, array $request): SalesReceipt
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        if (! isset($input['customer_organization_id'])) {
            throw new RuntimeException('customer_organization_id is required to create a sales receipt.');
        }

        /** @var Organization $billable */
        $billable = Organization::getByIdFromCompanyApp(
            (int) $input['customer_organization_id'],
            $company,
            $app,
        );

        $lines = new DataCollection(SalesReceiptLineData::class, array_map(
            fn (array $line): SalesReceiptLineData => new SalesReceiptLineData(
                description: (string) ($line['description'] ?? ''),
                quantity: (float) ($line['quantity'] ?? 1),
                unit_price_native: (float) $line['unit_price_native'],
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                discount_rate: isset($line['discount_rate']) ? (float) $line['discount_rate'] : null,
                discount_amount_native: (float) ($line['discount_amount_native'] ?? 0),
                tax_rate: isset($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'],
        ));

        return new CreateSalesReceiptAction(
            data: new SalesReceiptData(
                app: $app,
                company: $company,
                billable: $billable,
                lines: $lines,
                receipt_date: isset($input['receipt_date'])
                    ? Carbon::parse((string) $input['receipt_date'])
                    : Carbon::today(),
                currency: (string) $input['currency'],
                fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
                cash_account_id: null,
                payment_method_id: null,
                payment_id: null,
                receipt_number: $input['receipt_number'] ?? null,
                notes: $input['notes'] ?? null,
                internal_notes: $input['internal_notes'] ?? null,
                regional_compliance: $input['regional_compliance'] ?? null,
                tax_metadata: $input['tax_metadata'] ?? null,
                metadata: $input['metadata'] ?? null,
            ),
            user: $user,
        )->execute();
    }

    public function void(mixed $rootValue, array $request): SalesReceipt
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var SalesReceipt $receipt */
        $receipt = SalesReceipt::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new VoidSalesReceiptAction(
            salesReceipt: $receipt,
            voidReasonCode: (string) $request['void_reason_code'],
            user: $user,
        )->execute();
    }
}
