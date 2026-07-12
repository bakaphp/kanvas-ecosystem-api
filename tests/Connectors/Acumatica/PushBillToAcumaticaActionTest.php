<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
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
            function (string $entity, array $body, bool $release = false, array $files = [], ?array $findQuery = null) use (&$captured): array {
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

    public function test_creates_vendor_when_org_has_no_code_then_pushes_with_it(): void
    {
        $vendor = $this->seedTestOrganization('Brand New Vendor'); // no VENDOR_ID set
        $accountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $bill = $this->billWithCoding($vendor, $accountId, 0);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('findOrCreate')->once()
            ->andReturn(['id' => 'GUID-V', 'VendorID' => ['value' => 'V7777']]);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body, bool $release = false, array $files = [], ?array $findQuery = null) use (&$captured): array {
                $captured = $body;

                return ['id' => 'B1', 'ReferenceNbr' => ['value' => '000999']];
            }
        );

        $ref = new PushBillToAcumaticaAction($this->kanvasApp, $bill, $writer)->execute();

        $this->assertSame('000999', $ref);
        $this->assertSame(['value' => 'V7777'], $captured['Vendor']);

        /** @var Organization $fresh */
        $fresh = Organization::query()->where('id', $vendor->getId())->firstOrFail();
        $this->assertSame('V7777', (string) $fresh->get(CustomFieldEnum::VENDOR_ID->value));
    }

    public function test_refuses_to_push_a_bill_that_originated_in_acumatica(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');
        $bill = $this->billWithCoding($vendor, $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS), 0);
        $bill->source = 'acumatica';
        $bill->save();

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('push');

        $this->expectException(AcumaticaWriteException::class);

        new PushBillToAcumaticaAction($this->kanvasApp, $bill, $writer)->execute();
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
