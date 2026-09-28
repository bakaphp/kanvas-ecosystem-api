<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushBillNoteToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Mockery;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class PushBillNoteToAcumaticaActionTest extends ScribeTestCase
{
    private function pushedBill(): Bill
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
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
                bill_date: Carbon::parse('2026-06-01'),
            ),
            static::$cachedUser,
        )->execute();
        $bill->set(CustomFieldEnum::BILL_ID->value, 'BILL-GUID');
        $bill->set(CustomFieldEnum::BILL_REF->value, '66102685');

        return $bill;
    }

    public function test_appends_to_an_existing_note(): void
    {
        $bill = $this->pushedBill();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->once()->with('Bill/BILL-GUID')
            ->andReturn(['note' => ['value' => 'Called vendor about delivery.']]);
        $client->shouldReceive('put')->once()
            ->with('Bill', ['id' => 'BILL-GUID', 'note' => ['value' => "Called vendor about delivery.\nPaid via check."]])
            ->andReturn([]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $result = new PushBillNoteToAcumaticaAction($bill, $writer)->execute('Paid via check.');

        $this->assertSame("Called vendor about delivery.\nPaid via check.", $result);
    }

    public function test_sets_the_note_when_acumatica_has_none_yet(): void
    {
        $bill = $this->pushedBill();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->once()->with('Bill/BILL-GUID')->andReturn(['note' => []]);
        $client->shouldReceive('put')->once()
            ->with('Bill', ['id' => 'BILL-GUID', 'note' => ['value' => 'First note.']])
            ->andReturn([]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $result = new PushBillNoteToAcumaticaAction($bill, $writer)->execute('First note.');

        $this->assertSame('First note.', $result);
    }

    public function test_refuses_a_bill_that_was_never_pushed(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
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
                bill_date: Carbon::parse('2026-06-01'),
            ),
            static::$cachedUser,
        )->execute();

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('withSession');

        $this->expectException(AcumaticaWriteException::class);

        new PushBillNoteToAcumaticaAction($bill, $writer)->execute('Note.');
    }
}
