<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Mockery;
use Tests\Scribe\ScribeTestCase;

class PushInvoiceToAcumaticaActionTest extends ScribeTestCase
{
    private function invoice(DocumentTypeEnum $documentType, ?int $parentInvoiceId = null): Invoice
    {
        $customer = $this->seedTestOrganization('Acme Corporation');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000123');

        return Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => $documentType->value,
            'parent_invoice_id' => $parentInvoiceId,
            'invoice_number' => $documentType === DocumentTypeEnum::CREDIT_NOTE ? 'CRN-1' : 'INV-1',
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
    }

    public function test_pushes_a_regular_invoice_with_type_invoice(): void
    {
        $invoice = $this->invoice(DocumentTypeEnum::INVOICE);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body) use (&$captured): array {
                $captured = $body;

                return ['id' => 'GUID-1', 'ReferenceNbr' => ['value' => '000111']];
            }
        );

        $ref = new PushInvoiceToAcumaticaAction($invoice, $writer)->execute();

        $this->assertSame('000111', $ref);
        $this->assertSame(['value' => 'Invoice'], $captured['Type']);
    }

    public function test_pushes_a_credit_note_with_type_credit_memo(): void
    {
        $parent = $this->invoice(DocumentTypeEnum::INVOICE);
        $creditNote = $this->invoice(DocumentTypeEnum::CREDIT_NOTE, $parent->getId());

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body) use (&$captured): array {
                $captured = $body;

                return ['id' => 'GUID-2', 'ReferenceNbr' => ['value' => '000222']];
            }
        );

        $ref = new PushInvoiceToAcumaticaAction($creditNote, $writer)->execute();

        $this->assertSame('000222', $ref);
        $this->assertSame(['value' => 'Credit Memo'], $captured['Type']);
    }

    public function test_a_line_level_account_override_is_sent_as_the_acumatica_detail_account(): void
    {
        $controlAccountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $controlAccountCode = (string) Account::query()->where('id', $controlAccountId)->value('account_number');

        $creditNote = $this->invoice(DocumentTypeEnum::CREDIT_NOTE);
        InvoiceLine::create([
            'invoice_id' => $creditNote->id,
            'sort_order' => 0,
            'account_id' => $controlAccountId,
            'description' => 'Promotion Discount',
            'quantity' => 1,
            'unit_price_native' => 100.0,
            'line_total_native' => 100.0,
        ]);
        $creditNote->refresh();
        $creditNote->load('lines');

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body) use (&$captured): array {
                $captured = $body;

                return ['id' => 'GUID-3', 'ReferenceNbr' => ['value' => '000333']];
            }
        );

        new PushInvoiceToAcumaticaAction($creditNote, $writer)->execute();

        $this->assertSame($controlAccountCode, $captured['Details'][0]['Account']['value']);
    }

    public function test_zero_tax_document_gets_the_configured_exempt_tax_zone_on_header_and_lines(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::ACUMATICA_TAX_EXEMPT_ZONE->value, 'NONTAX');
        $creditNote = $this->invoice(DocumentTypeEnum::CREDIT_NOTE);
        InvoiceLine::create([
            'invoice_id' => $creditNote->id,
            'sort_order' => 0,
            'description' => 'Promotion Discount',
            'quantity' => 1,
            'unit_price_native' => 100.0,
            'line_total_native' => 100.0,
        ]);
        $creditNote->refresh();
        $creditNote->load('lines');

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body) use (&$captured): array {
                $captured = $body;

                return ['id' => 'GUID-4', 'ReferenceNbr' => ['value' => '000444']];
            }
        );

        new PushInvoiceToAcumaticaAction($creditNote, $writer)->execute();

        $this->assertSame('NONTAX', $captured['TaxZone']['value']);
        $this->assertSame('NONTAX', $captured['Details'][0]['TaxCategory']['value']);
    }

    public function test_a_taxable_document_is_not_marked_exempt_even_when_a_zone_is_configured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::ACUMATICA_TAX_EXEMPT_ZONE->value, 'NONTAX');
        $customer = $this->seedTestOrganization('Acme Corporation');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000123');

        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => DocumentTypeEnum::INVOICE->value,
            'invoice_number' => 'INV-TAXED-1',
            'customer_organization_id' => $customer->getId(),
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 100.0, 'tax_native' => 19.0, 'total_native' => 119.0, 'paid_native' => 0.0, 'balance_due_native' => 119.0,
            'subtotal_base' => 100.0, 'tax_base' => 19.0, 'total_base' => 119.0, 'paid_base' => 0.0, 'balance_due_base' => 119.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body) use (&$captured): array {
                $captured = $body;

                return ['id' => 'GUID-5', 'ReferenceNbr' => ['value' => '000555']];
            }
        );

        new PushInvoiceToAcumaticaAction($invoice, $writer)->execute();

        $this->assertArrayNotHasKey('TaxZone', $captured);
    }
}
