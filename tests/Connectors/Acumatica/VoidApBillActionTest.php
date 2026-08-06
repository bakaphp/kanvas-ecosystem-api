<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\VoidApBillAction;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Mockery;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class VoidApBillActionTest extends ScribeTestCase
{
    private function pushedBill(Organization $vendor): Bill
    {
        $bill = new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Materials',
                        quantity: 1,
                        unit_price_native: 50.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_number: 'BILL-11',
                bill_date: Carbon::parse('2026-06-01'),
            ),
            static::$cachedUser,
        )->execute();
        $bill->set(CustomFieldEnum::BILL_ID->value, 'BILL-GUID');
        $bill->set(CustomFieldEnum::BILL_REF->value, '66102685');

        return $bill;
    }

    private function debitAdjQuery(): array
    {
        return ['Bill', Mockery::on(
            fn (array $q): bool => isset($q['$filter']) && str_contains($q['$filter'], "Type eq 'Debit Adj.'"),
        )];
    }

    public function test_voids_a_bill_with_no_existing_debit_adjustment(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');
        $bill = $this->pushedBill($vendor);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->with(...$this->debitAdjQuery())->twice()->andReturn(
            [],
            [['id' => 'DEBIT-GUID', 'ReferenceNbr' => ['value' => '66110503']]],
        );
        $client->shouldReceive('invokeAction')->once()
            ->with('Bill', 'ReverseBill', ['entity' => ['id' => 'BILL-GUID']])->andReturn(204);
        $client->shouldReceive('get')->with('Bill/DEBIT-GUID')->twice()->andReturn(
            ['Status' => ['value' => 'On Hold']],
            ['Status' => ['value' => 'Balanced']],
        );
        $client->shouldReceive('put')->once()
            ->with('Bill', ['id' => 'DEBIT-GUID', 'Hold' => ['value' => false]])->andReturn([]);
        $client->shouldReceive('invokeAction')->once()
            ->with('Bill', 'ReleaseBill', ['entity' => ['id' => 'DEBIT-GUID']])->andReturn(204);
        $client->shouldReceive('put')->once()->with('Check', Mockery::type('array'))->andReturn([]);
        $client->shouldReceive('invokeAction')->once()
            ->with('Check', 'ReleaseCheck', ['entity' => ['id' => 'DEBIT-GUID']])->andReturn(204);
        $client->shouldReceive('get')->with('Check/DEBIT-GUID')->once()
            ->andReturn(['Status' => ['value' => 'Closed']]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $ref = new VoidApBillAction($bill, $writer)->execute();

        $this->assertSame('66110503', $ref);
    }

    public function test_resumes_an_existing_unreleased_debit_adjustment_instead_of_reversing_again(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');
        $bill = $this->pushedBill($vendor);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->with(...$this->debitAdjQuery())->once()->andReturn(
            [['id' => 'DEBIT-GUID', 'ReferenceNbr' => ['value' => '66110503']]],
        );
        $client->shouldNotReceive('invokeAction')->with('Bill', 'ReverseBill', Mockery::any());
        $client->shouldReceive('get')->with('Bill/DEBIT-GUID')->once()
            ->andReturn(['Status' => ['value' => 'Balanced']]);
        $client->shouldReceive('put')->once()->with('Check', Mockery::type('array'))->andReturn([]);
        $client->shouldReceive('invokeAction')->once()
            ->with('Check', 'ReleaseCheck', ['entity' => ['id' => 'DEBIT-GUID']])->andReturn(204);
        $client->shouldReceive('get')->with('Check/DEBIT-GUID')->once()
            ->andReturn(['Status' => ['value' => 'Closed']]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $ref = new VoidApBillAction($bill, $writer)->execute();

        $this->assertSame('66110503', $ref);
    }

    public function test_returns_immediately_when_the_existing_debit_adjustment_is_already_closed(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');
        $bill = $this->pushedBill($vendor);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->with(...$this->debitAdjQuery())->once()->andReturn(
            [['id' => 'DEBIT-GUID', 'ReferenceNbr' => ['value' => '66110503']]],
        );
        $client->shouldNotReceive('invokeAction');
        $client->shouldNotReceive('put');
        $client->shouldReceive('get')->with('Bill/DEBIT-GUID')->once()
            ->andReturn(['Status' => ['value' => 'Closed']]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $ref = new VoidApBillAction($bill, $writer)->execute();

        $this->assertSame('66110503', $ref);
    }
}
