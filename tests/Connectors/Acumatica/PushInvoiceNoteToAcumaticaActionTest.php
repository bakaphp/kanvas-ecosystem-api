<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceNoteToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Mockery;
use Tests\Scribe\ScribeTestCase;

class PushInvoiceNoteToAcumaticaActionTest extends ScribeTestCase
{
    private function pushedInvoice(): Invoice
    {
        $customer = $this->seedTestOrganization('Acme Corporation');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000123');

        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-1',
            'customer_organization_id' => $customer->getId(),
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 100.0, 'total_native' => 100.0, 'paid_native' => 0.0, 'balance_due_native' => 100.0,
            'subtotal_base' => 100.0, 'total_base' => 100.0, 'paid_base' => 0.0, 'balance_due_base' => 100.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);
        $invoice->set(CustomFieldEnum::INVOICE_ID->value, 'INV-GUID');
        $invoice->set(CustomFieldEnum::INVOICE_REF->value, '6300164923');

        return $invoice;
    }

    public function test_appends_to_an_existing_note(): void
    {
        $invoice = $this->pushedInvoice();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->once()->with('Invoice/INV-GUID')
            ->andReturn(['note' => ['value' => 'Called customer about delivery.']]);
        $client->shouldReceive('put')->once()
            ->with('Invoice', ['id' => 'INV-GUID', 'note' => ['value' => "Called customer about delivery.\nApproved by manager."]])
            ->andReturn([]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('withSession')->once()->andReturnUsing(fn (callable $cb) => $cb($client));

        $result = new PushInvoiceNoteToAcumaticaAction($invoice, $writer)->execute('Approved by manager.');

        $this->assertSame("Called customer about delivery.\nApproved by manager.", $result);
    }

    public function test_refuses_an_invoice_that_was_never_pushed(): void
    {
        $customer = $this->seedTestOrganization('Acme Corporation');
        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-2',
            'customer_organization_id' => $customer->getId(),
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 100.0, 'total_native' => 100.0, 'paid_native' => 0.0, 'balance_due_native' => 100.0,
            'subtotal_base' => 100.0, 'total_base' => 100.0, 'paid_base' => 0.0, 'balance_due_base' => 100.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('withSession');

        $this->expectException(AcumaticaWriteException::class);

        new PushInvoiceNoteToAcumaticaAction($invoice, $writer)->execute('Note.');
    }
}
