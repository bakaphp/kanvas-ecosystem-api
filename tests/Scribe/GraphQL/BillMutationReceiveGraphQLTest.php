<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Happy-path GraphQL test for `receiveScribeBill`. The original Phase 4 break (the resolver still
 * referencing `vendor_billable_type`/`vendor_billable_id` after the columns were dropped) wasn't
 * caught by any test because the only mutation test asserted the error branch. This fills the gap.
 */
class BillMutationReceiveGraphQLTest extends ScribeTestCase
{
    public function test_receive_scribe_bill_with_vendor_organization_links_and_freezes_snapshot(): void
    {
        $vendor = $this->seedTestOrganization('Mercury Bank');
        $expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        // Build a draft Bill that already has vendor_organization_id set
        $draft = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Vendor service',
                        quantity: 1,
                        unit_price_native: 250.0,
                        expense_account_id: $expenseAccountId,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_number: 'BN-' . uniqid(),
                bill_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-07-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        // The CreateBillAction doesn't set vendor_organization_id from BillData.vendor.
        // Stamp it ourselves so the resolver has the FK to look up.
        $draft->vendor_organization_id = (int) $vendor->id;
        $draft->save();

        $response = $this->graphQL('
            mutation($id: ID!) {
                receiveScribeBill(id: $id) {
                    id
                    document_status
                    bill_number
                }
            }
        ', ['id' => $draft->id])->assertSuccessful();

        $payload = $response->json('data.receiveScribeBill');
        $this->assertSame('RECEIVED', $payload['document_status']);

        /** @var Bill $received */
        $received = Bill::query()->where('id', $draft->id)->firstOrFail();
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $received->document_status);
        $this->assertSame((int) $vendor->id, (int) $received->vendor_organization_id);
        $this->assertSame($vendor->name, $received->vendor_display_name, 'snapshot frozen from Org.');
    }
}
