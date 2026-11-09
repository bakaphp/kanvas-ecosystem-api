<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Create and Update once persisted bill lines through two copies of the same loop, and only the create
 * copy wrote `subaccount_id`: editing a draft silently dropped the subaccount off every line.
 */
class UpdateBillKeepsLineSubaccountTest extends ScribeTestCase
{
    public function test_updating_a_draft_keeps_the_subaccount_on_its_lines(): void
    {
        $subaccount = Subaccount::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'sub_code' => 'OPS-DR-001',
            'source' => 'kanvas',
        ]);
        $expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS);
        $vendor = $this->seedTestOrganization('Subaccount Vendor');

        $bill = new CreateBillAction(
            data: $this->billData($vendor, $expenseAccountId, (int) $subaccount->getId(), 100.0),
            user: static::$cachedUser,
        )->execute();
        $this->assertSame((int) $subaccount->getId(), (int) $bill->lines->first()->subaccount_id);

        $updated = new UpdateBillAction(
            bill: $bill,
            data: $this->billData($vendor, $expenseAccountId, (int) $subaccount->getId(), 250.0),
            user: static::$cachedUser,
        )->execute();

        $this->assertEquals(250.0, (float) $updated->total_native);
        $this->assertCount(1, $updated->lines);
        $this->assertSame((int) $subaccount->getId(), (int) $updated->lines->first()->subaccount_id);
    }

    private function billData(
        Organization $vendor,
        int $expenseAccountId,
        int $subaccountId,
        float $unitPrice
    ): BillData {
        return new BillData(
            app: $this->kanvasApp,
            company: $this->company,
            vendor: $vendor,
            lines: new DataCollection(BillLineData::class, [
                new BillLineData(
                    description: 'Hosting',
                    quantity: 1.0,
                    unit_price_native: $unitPrice,
                    expense_account_id: $expenseAccountId,
                    subaccount_id: $subaccountId,
                ),
            ]),
            currency: 'USD',
            fx_rate_to_base: 1.0,
            bill_date: Carbon::parse('2026-06-15'),
        );
    }
}
