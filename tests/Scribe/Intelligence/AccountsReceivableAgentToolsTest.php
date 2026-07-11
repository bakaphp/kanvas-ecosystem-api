<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
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

        $result = new FindCustomerTool()->__invoke(name: 'Acme Corp');

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

        $found = new FindInvoiceTool()->__invoke(invoice_number: 'INV-5150');
        $this->assertTrue($found['found']);
        $this->assertSame('Acme Corporation', $found['customer']);
        $this->assertSame(1000.0, (float) $found['balance_due_native']);
        $this->assertCount(1, $found['lines']);
        $this->assertSame('RL-KP336', $found['lines'][0]['sku']);

        $missing = new FindInvoiceTool()->__invoke(invoice_number: 'DOES-NOT-EXIST');
        $this->assertFalse($missing['found']);
    }
}
