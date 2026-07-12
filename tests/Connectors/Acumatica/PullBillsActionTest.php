<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\PullBillsAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Tests\Scribe\ScribeTestCase;

class PullBillsActionTest extends ScribeTestCase
{
    public function test_pull_builds_coded_multi_line_bill_mirroring_source_gl(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');

        $accountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $accountCode = (string) Account::query()->where('id', $accountId)->value('account_number');

        $subaccount = Subaccount::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'sub_code' => 'DC1AV1',
            'source' => 'acumatica',
        ]);

        $header = [
            'DocType' => 'INV',
            'RefNbr' => 'AP-0001',
            'AcctCD' => 'V0000505',
            'CuryID' => 'USD',
            'CuryOrigDocAmt' => 226.0,
            'CuryDocBal' => 226.0,
            'DocDate' => '2026-06-01',
            'DueDate' => '2026-07-01',
            'DocDesc' => 'Test bill',
        ];

        $linesByDoc = ['INV|AP-0001' => [
            ['TranType' => 'INV', 'RefNbr' => 'AP-0001', 'AccountCD' => $accountCode, 'SubCD' => 'DC1AV1', 'Qty' => 2.0, 'CuryUnitCost' => 100.0, 'CuryTranAmt' => 200.0, 'TranDesc' => 'Materials'],
            ['TranType' => 'INV', 'RefNbr' => 'AP-0001', 'AccountCD' => $accountCode, 'SubCD' => '', 'Qty' => 0.0, 'CuryUnitCost' => 0.0, 'CuryTranAmt' => 20.0, 'TranDesc' => 'Handling'],
        ]];

        $count = new PullBillsAction($this->kanvasApp, $this->company, static::$cachedUser, 2)
            ->processRows([$header], $linesByDoc);

        $this->assertSame(1, $count);

        /** @var Bill $bill */
        $bill = Bill::query()
            ->where('companies_id', $this->company->getId())
            ->where('bill_number', 'AP-0001')
            ->firstOrFail();

        $lines = $bill->lines()->orderBy('sort_order')->get();

        // 2 coded lines + 1 reconciliation line (226 header − 220 lines = 6 tax delta)
        $this->assertCount(3, $lines);

        $this->assertSame($accountId, (int) $lines[0]->expense_account_id);
        $this->assertSame((int) $subaccount->id, (int) $lines[0]->subaccount_id);

        // qty-0 service row becomes one line at the row amount, still coded to the account
        $this->assertSame(20.0, (float) $lines[1]->unit_price_native);
        $this->assertSame($accountId, (int) $lines[1]->expense_account_id);
        $this->assertNull($lines[1]->subaccount_id);

        $this->assertSame('Tax & adjustments', $lines[2]->description);
        $this->assertSame(6.0, (float) $lines[2]->unit_price_native);
        $this->assertNull($lines[2]->expense_account_id);
    }
}
