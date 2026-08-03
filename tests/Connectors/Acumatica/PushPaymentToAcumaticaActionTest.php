<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushPaymentToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Mockery;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class PushPaymentToAcumaticaActionTest extends ScribeTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function payment(string $direction, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'amount_native' => 500.0,
            'amount_base' => 500.0,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'payment_date' => Carbon::parse('2026-07-01'),
            'direction' => $direction,
            'method' => 'check',
            'status' => 'cleared',
            'source' => 'kanvas',
            'reference' => 'CHK-1',
        ], $overrides));
    }

    private function receivedBill(Organization $vendor): Bill
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
                        unit_price_native: 500.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_number: 'AP000777',
                bill_date: Carbon::parse('2026-06-01'),
            ),
            static::$cachedUser,
        )->execute();
        $bill->bill_number = 'AP000777';
        $bill->save();
        $bill->set(CustomFieldEnum::BILL_REF->value, 'AP000777');

        return $bill;
    }

    public function test_ap_payment_pushes_a_check_via_two_step_put(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');
        $bill = $this->receivedBill($vendor);

        $payment = $this->payment('outbound');
        BillPaymentAllocation::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'bill_id' => $bill->id,
            'payment_id' => $payment->id,
            'source_type' => 'payment',
            'status' => 'active',
            'amount_native' => 500.0,
            'amount_base' => 500.0,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'allocated_at' => Carbon::parse('2026-07-01'),
        ]);

        $calls = [];
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->once()->with('Check', Mockery::type('array'))->andReturn([]);
        $client->shouldReceive('put')->twice()->andReturnUsing(
            function (string $entity, array $body) use (&$calls): array {
                $calls[] = [$entity, $body];

                return ['id' => 'PAY-1', 'ReferenceNbr' => ['value' => '000900'], 'Status' => ['value' => 'Pending Print']];
            }
        );

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(
            fn (callable $callback) => $callback($client)
        );

        $action = new PushPaymentToAcumaticaAction($payment, $writer);
        $ref = $action->execute();

        $this->assertSame('000900', $ref);
        $this->assertSame('PAY-1', $payment->get(CustomFieldEnum::PAYMENT_ID->value));
        $this->assertSame('Pending Print', $action->getLastPushedStatus());

        [$step1Entity, $step1Body] = $calls[0];
        [$step2Entity, $step2Body] = $calls[1];

        $this->assertSame('Check', $step1Entity);
        $this->assertSame(['value' => 'Payment'], $step1Body['Type']);
        $this->assertSame(['value' => 'V0000505'], $step1Body['Vendor']);
        $this->assertSame(['value' => 'CHK-1'], $step1Body['PaymentRef']);
        $this->assertSame(['value' => true], $step1Body['Hold']);
        $this->assertArrayNotHasKey('PaymentAmount', $step1Body);
        $this->assertSame(['value' => 'Bill'], $step1Body['Details'][0]['DocType']);
        $this->assertSame(['value' => 'AP000777'], $step1Body['Details'][0]['ReferenceNbr']);
        $this->assertSame(['value' => 500.0], $step1Body['Details'][0]['AmountPaid']);

        $this->assertSame('Check', $step2Entity);
        $this->assertSame(['value' => 'Payment'], $step2Body['Type']);
        $this->assertSame(['value' => '000900'], $step2Body['ReferenceNbr']);
        $this->assertSame(['value' => 500.0], $step2Body['PaymentAmount']);
        $this->assertSame(['value' => false], $step2Body['Hold']);
    }

    public function test_ar_receipt_pushes_a_payment_applied_to_the_customer_invoice(): void
    {
        $customer = $this->seedTestOrganization('Acme Corporation');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000123');

        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'AR000123',
            'customer_organization_id' => $customer->getId(),
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 500.0, 'total_native' => 500.0, 'paid_native' => 0.0, 'balance_due_native' => 500.0,
            'subtotal_base' => 500.0, 'total_base' => 500.0, 'paid_base' => 0.0, 'balance_due_base' => 500.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);
        $invoice->set(CustomFieldEnum::INVOICE_REF->value, 'AR000123');

        $payment = $this->payment('inbound', ['reference' => 'ACH-77']);
        InvoicePaymentAllocation::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'source_type' => 'payment',
            'status' => 'active',
            'amount_native' => 500.0,
            'amount_base' => 500.0,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'allocated_at' => Carbon::parse('2026-07-01'),
        ]);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body, bool $release = false, array $files = [], ?array $findQuery = null) use (&$captured): array {
                $captured = $body;

                return ['id' => 'PAY-9', 'ReferenceNbr' => ['value' => '000999']];
            }
        );

        $ref = new PushPaymentToAcumaticaAction($payment, $writer)->execute();

        $this->assertSame('000999', $ref);
        $this->assertSame(['value' => 'Payment'], $captured['Type']);
        $this->assertSame(['value' => 'C0000123'], $captured['CustomerID']);
        $this->assertSame(['value' => 'INV'], $captured['DocumentsToApply'][0]['DocType']);
        $this->assertSame(['value' => 'AR000123'], $captured['DocumentsToApply'][0]['ReferenceNbr']);
    }

    public function test_is_idempotent_when_already_pushed(): void
    {
        $payment = $this->payment('outbound');
        $payment->set(CustomFieldEnum::PAYMENT_ID->value, 'ALREADY');

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('push');

        $ref = new PushPaymentToAcumaticaAction($payment, $writer)->execute();

        $this->assertSame('ALREADY', $ref);
    }

    public function test_refuses_a_payment_that_originated_in_acumatica(): void
    {
        $payment = $this->payment('outbound', ['source' => 'acumatica']);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('push');

        $this->expectException(AcumaticaWriteException::class);

        new PushPaymentToAcumaticaAction($payment, $writer)->execute();
    }
}
