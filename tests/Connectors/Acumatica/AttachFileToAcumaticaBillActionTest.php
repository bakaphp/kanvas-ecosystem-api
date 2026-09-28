<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\AttachFileToAcumaticaBillAction;
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

class AttachFileToAcumaticaBillActionTest extends ScribeTestCase
{
    private function billWithLines(): Bill
    {
        $vendor = $this->seedTestOrganization('Globex Supply');

        return new CreateBillAction(
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
    }

    public function test_refuses_a_bill_that_was_never_pushed(): void
    {
        $bill = $this->billWithLines();

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('withSession');

        $this->expectException(AcumaticaWriteException::class);

        new AttachFileToAcumaticaBillAction($bill, 'https://example.test/invoice.pdf', 'invoice.pdf', $writer)->execute();
    }

    public function test_throws_when_the_bill_has_no_files_put_link(): void
    {
        $bill = $this->billWithLines();
        $bill->set(CustomFieldEnum::BILL_ID->value, 'BILL-GUID');

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->once()->with('Bill/BILL-GUID')->andReturn(['id' => 'BILL-GUID']);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $this->expectException(AcumaticaWriteException::class);
        $this->expectExceptionMessage('no files:put link');

        new AttachFileToAcumaticaBillAction($bill, 'https://example.test/invoice.pdf', 'invoice.pdf', $writer)->execute();
    }
}
