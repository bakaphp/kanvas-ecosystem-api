<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchInvoicesForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyArPaymentTool;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Tests\Scribe\ScribeTestCase;

class AccountsReceivableAgentToolsTest extends ScribeTestCase
{
    public function test_find_customer_tool_returns_acumatica_code(): void
    {
        $customer = $this->seedTestOrganization('Acme Corporation');
        $customer->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0000123');

        $result = new FindCustomerTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(name: 'Acme Corp');

        $this->assertGreaterThanOrEqual(1, (int) $result['count']);
        $codes = array_column($result['customers'], 'acumatica_customer_code');
        $this->assertContains('C0000123', $codes);
    }

    public function test_find_invoice_returns_full_detail_or_not_found(): void
    {
        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-5150',
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 1250.0,
            'total_native' => 1250.0,
            'paid_native' => 250.0,
            'balance_due_native' => 1000.0,
            'subtotal_base' => 1250.0,
            'total_base' => 1250.0,
            'paid_base' => 250.0,
            'balance_due_base' => 1000.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'due_date' => Carbon::parse('2026-07-01'),
            'source' => 'acumatica',
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'sku' => 'RL-KP336',
            'description' => 'Kraken Elite 360',
            'quantity' => 5,
            'unit_price_native' => 250.0,
        ]);

        $found = new FindInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(invoice_number: 'INV-5150');
        $this->assertTrue($found['found']);
        $this->assertSame('Acme Corporation', $found['customer']);
        $this->assertSame(1000.0, (float) $found['balance_due_native']);
        $this->assertCount(1, $found['lines']);
        $this->assertSame('RL-KP336', $found['lines'][0]['sku']);

        $missing = new FindInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(invoice_number: 'DOES-NOT-EXIST');
        $this->assertFalse($missing['found']);
    }

    public function test_list_overdue_invoices_filters_by_customer(): void
    {
        $target = $this->seedTestOrganization('Overdue Target Inc');
        $other = $this->seedTestOrganization('Someone Else Inc');

        $overdueForTarget = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => DocumentTypeEnum::INVOICE,
            'invoice_number' => 'INV-OVERDUE-TARGET',
            'customer_organization_id' => $target->getId(),
            'billable_display_name' => 'Overdue Target Inc',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 500.0, 'total_native' => 500.0, 'paid_native' => 0.0, 'balance_due_native' => 500.0,
            'subtotal_base' => 500.0, 'total_base' => 500.0, 'paid_base' => 0.0, 'balance_due_base' => 500.0,
            'issued_date' => Carbon::today()->subDays(30),
            'due_date' => Carbon::today()->subDays(10),
            'source' => 'kanvas',
        ]);

        Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => DocumentTypeEnum::INVOICE,
            'invoice_number' => 'INV-OVERDUE-OTHER',
            'customer_organization_id' => $other->getId(),
            'billable_display_name' => 'Someone Else Inc',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 700.0, 'total_native' => 700.0, 'paid_native' => 0.0, 'balance_due_native' => 700.0,
            'subtotal_base' => 700.0, 'total_base' => 700.0, 'paid_base' => 0.0, 'balance_due_base' => 700.0,
            'issued_date' => Carbon::today()->subDays(30),
            'due_date' => Carbon::today()->subDays(10),
            'source' => 'kanvas',
        ]);

        $result = new ListOverdueInvoicesTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer: 'Overdue Target');

        $numbers = array_column($result['invoices'], 'invoice_number');
        $this->assertContains('INV-OVERDUE-TARGET', $numbers);
        $this->assertNotContains('INV-OVERDUE-OTHER', $numbers);
        $this->assertSame($overdueForTarget->invoice_number, $numbers[0]);
    }

    public function test_apply_ar_payment_reports_not_found_for_unknown_invoice(): void
    {
        $result = new ApplyArPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: 999999999, amount: 100.0, reference: 'CHK-1');

        $this->assertFalse($result['applied']);
        $this->assertSame('invoice_not_found', $result['reason']);
    }

    public function test_apply_ar_payment_reports_not_pushed_when_invoice_has_no_acumatica_ref(): void
    {
        $invoice = Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-NOPUSH',
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 300.0, 'total_native' => 300.0, 'paid_native' => 0.0, 'balance_due_native' => 300.0,
            'subtotal_base' => 300.0, 'total_base' => 300.0, 'paid_base' => 0.0, 'balance_due_base' => 300.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);

        $result = new ApplyArPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: (int) $invoice->id, amount: 100.0, reference: 'CHK-1');

        $this->assertFalse($result['applied']);
        $this->assertSame('invoice_not_pushed', $result['reason']);
    }

    public function test_match_invoices_for_payment_flags_the_exact_invoice(): void
    {
        $customer = $this->seedTestOrganization('Acme Corporation');

        Invoice::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'document_type' => 'invoice',
            'invoice_number' => 'INV-9001',
            'customer_organization_id' => $customer->getId(),
            'billable_display_name' => 'Acme Corporation',
            'document_status' => InvoiceDocumentStatusEnum::ISSUED->value,
            'currency' => 'USD',
            'fx_rate_to_base' => 1.0,
            'subtotal_native' => 1200.0, 'total_native' => 1200.0, 'paid_native' => 0.0, 'balance_due_native' => 1200.0,
            'subtotal_base' => 1200.0, 'total_base' => 1200.0, 'paid_base' => 0.0, 'balance_due_base' => 1200.0,
            'issued_date' => Carbon::parse('2026-06-01'),
            'source' => 'kanvas',
        ]);

        $result = new MatchInvoicesForPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer: 'Acme Corp', amount: 1200.0);

        $this->assertNotEmpty($result['open_invoices']);
        $this->assertSame('INV-9001', $result['exact_match']);
    }
}
