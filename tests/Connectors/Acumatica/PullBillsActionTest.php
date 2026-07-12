<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\PullBillsAction;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportBill;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use ReflectionMethod;
use Tests\Scribe\ScribeTestCase;

class PullBillsActionTest extends ScribeTestCase
{
    /**
     * Exercises the line-coding + tax reconciliation directly (buildLines) against seeded Scribe
     * account/subaccount rows — no SQL replica, no vendor lookup — so it runs the same in CI.
     */
    public function test_build_lines_codes_each_line_and_reconciles_tax(): void
    {
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
            'DocDesc' => 'Test bill',
        ];

        $rawLines = [
            ['AccountCD' => $accountCode, 'SubCD' => 'DC1AV1', 'Qty' => 2.0, 'CuryUnitCost' => 100.0, 'CuryTranAmt' => 200.0, 'TranDesc' => 'Materials'],
            ['AccountCD' => $accountCode, 'SubCD' => '', 'Qty' => 0.0, 'CuryUnitCost' => 0.0, 'CuryTranAmt' => 20.0, 'TranDesc' => 'Handling'],
        ];

        $buildLines = new ReflectionMethod(PullBillsAction::class, 'buildLines');
        $buildLines->setAccessible(true);

        /** @var array<int, BillLineData> $lines */
        $lines = $buildLines->invoke(
            new PullBillsAction($this->kanvasApp, $this->company, static::$cachedUser, 2),
            $rawLines,
            AcumaticaImportBill::from($header),
        );

        // 2 coded lines + 1 reconciliation line (226 header − 220 lines = 6 tax delta)
        $this->assertCount(3, $lines);

        $this->assertSame($accountId, $lines[0]->expense_account_id);
        $this->assertSame((int) $subaccount->id, $lines[0]->subaccount_id);
        $this->assertSame(2.0, $lines[0]->quantity);
        $this->assertSame(100.0, $lines[0]->unit_price_native);

        // qty-0 service row becomes one line at the row amount, still coded to the account
        $this->assertSame($accountId, $lines[1]->expense_account_id);
        $this->assertNull($lines[1]->subaccount_id);
        $this->assertSame(20.0, $lines[1]->unit_price_native);

        $this->assertSame('Tax & adjustments', $lines[2]->description);
        $this->assertSame(6.0, $lines[2]->unit_price_native);
        $this->assertNull($lines[2]->expense_account_id);
    }
}
