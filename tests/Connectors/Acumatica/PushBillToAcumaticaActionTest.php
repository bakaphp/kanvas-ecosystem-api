<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Mockery;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class PushBillToAcumaticaActionTest extends ScribeTestCase
{
    private function billWithCoding(Organization $vendor, int $accountId, int $subaccountId): Bill
    {
        return new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Freight',
                        quantity: 2,
                        unit_price_native: 250.0,
                        expense_account_id: $accountId,
                        subaccount_id: $subaccountId,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_number: 'INV-778',
                bill_date: Carbon::parse('2026-06-15'),
            ),
            static::$cachedUser,
        )->execute();
    }

    public function test_pushes_bill_payload_with_translated_coding_and_stores_reference(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');

        $accountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $accountCode = (string) Account::query()->where('id', $accountId)->value('account_number');
        $subaccount = Subaccount::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'sub_code' => 'LOG-CA-000',
            'source' => 'acumatica',
        ]);

        $bill = $this->billWithCoding($vendor, $accountId, (int) $subaccount->id);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body, bool $release) use (&$captured): array {
                $captured = [$entity, $body, $release];

                return ['id' => 'GUID-1', 'ReferenceNbr' => ['value' => '66104999']];
            }
        );

        $ref = new PushBillToAcumaticaAction($this->kanvasApp, $bill, $writer)->execute();

        $this->assertSame('66104999', $ref);
        $this->assertSame('GUID-1', $bill->get(CustomFieldEnum::BILL_ID->value));
        $this->assertSame('66104999', (string) $bill->get(CustomFieldEnum::BILL_REF->value));

        [$entity, $body, $release] = $captured;
        $this->assertSame('Bill', $entity);
        $this->assertTrue($release);
        $this->assertSame(['value' => 'Bill'], $body['Type']);
        $this->assertSame(['value' => 'V0000505'], $body['Vendor']);
        $this->assertSame(['value' => 'INV-778'], $body['VendorRef']);

        $line = $body['Details'][0];
        $this->assertSame(['value' => $accountCode], $line['Account']);
        $this->assertSame(['value' => 'LOG-CA-000'], $line['Subaccount']);
        $this->assertSame(['value' => 2.0], $line['Qty']);
        $this->assertSame(['value' => 250.0], $line['UnitCost']);
    }

    public function test_is_idempotent_when_already_pushed(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');
        $bill = $this->billWithCoding($vendor, $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS), 0);
        $bill->set(CustomFieldEnum::BILL_ID->value, 'ALREADY-THERE');

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('push');

        $ref = new PushBillToAcumaticaAction($this->kanvasApp, $bill, $writer)->execute();

        $this->assertSame('ALREADY-THERE', $ref);
    }
}
