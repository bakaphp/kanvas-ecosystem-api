<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AnswerQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConvertQuoteToInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateInvoicePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuotePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendQuoteTool;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Tests\Scribe\ScribeTestCase;

class QuoteAgentToolsTest extends ScribeTestCase
{
    public function test_create_quote_creates_a_draft_with_computed_totals(): void
    {
        $this->seedTestOrganization('Northwind Traders');

        $result = $this->createQuote('Northwind Traders', [
            ['description' => 'Implementation', 'unit_price' => 1000.0, 'quantity' => 2],
            ['description' => 'Support retainer', 'unit_price' => 500.0, 'tax_amount' => 45.0],
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame(QuoteStatusEnum::DRAFT->value, $result['status']);
        $this->assertSame(2500.0, (float) $result['subtotal']);
        $this->assertSame(2545.0, (float) $result['total']);

        $quote = Quote::getById($result['quote_id']);
        $this->assertNull($quote->quote_number, 'A draft quote is not numbered until it is sent.');
        $this->assertCount(2, $quote->lines);
    }

    public function test_create_quote_refuses_to_guess_the_customer(): void
    {
        $result = $this->createQuote('No Such Customer At All', [
            ['description' => 'Anything', 'unit_price' => 10.0],
        ]);

        $this->assertFalse($result['created']);
        $this->assertSame('customer_not_found', $result['reason']);
    }

    /** Shared with create_ar_invoice / create_ar_credit_memo — a close call names the candidates, never picks one. */
    public function test_create_quote_names_the_candidates_when_the_customer_is_ambiguous(): void
    {
        $this->seedTestOrganization('Vertex Logistics East');
        $this->seedTestOrganization('Vertex Logistics West');

        $result = $this->createQuote('Vertex Logistics', [
            ['description' => 'Anything', 'unit_price' => 10.0],
        ]);

        $this->assertFalse($result['created']);
        $this->assertSame('customer_ambiguous', $result['reason']);
        $this->assertStringContainsString('Vertex Logistics East', $result['message']);
        $this->assertStringContainsString('Vertex Logistics West', $result['message']);
    }

    public function test_create_quote_needs_at_least_one_priced_line(): void
    {
        $this->seedTestOrganization('Lineless Corp');

        $result = $this->createQuote('Lineless Corp', [['description' => 'No price given']]);

        $this->assertFalse($result['created']);
        $this->assertSame('lines_required', $result['reason']);
    }

    public function test_send_quote_numbers_it_and_freezes_the_customer(): void
    {
        $this->seedTestOrganization('Sendable Inc');
        $created = $this->createQuote('Sendable Inc', [['description' => 'Work', 'unit_price' => 750.0]]);

        $sent = new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertTrue($sent['sent']);
        $this->assertSame(QuoteStatusEnum::SENT->value, $sent['status']);
        $this->assertNotEmpty($sent['quote_number']);
        $this->assertSame('Sendable Inc', $sent['customer']);
        $this->assertNotNull($sent['valid_until']);
    }

    /** A model that repeats itself must not renumber the quote the customer already has. */
    public function test_sending_the_same_quote_twice_keeps_the_first_number(): void
    {
        $this->seedTestOrganization('Double Send Co');
        $created = $this->createQuote('Double Send Co', [['description' => 'Work', 'unit_price' => 100.0]]);

        $tool = new SendQuoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
        $first = $tool->__invoke(quote_id: $created['quote_id']);
        $second = $tool->__invoke(quote_id: $created['quote_id']);

        $this->assertTrue($second['sent']);
        $this->assertSame($first['quote_number'], $second['quote_number']);
    }

    public function test_quote_travels_from_draft_to_a_draft_invoice(): void
    {
        $this->seedTestOrganization('Full Journey Ltd');
        $created = $this->createQuote('Full Journey Ltd', [['description' => 'Project', 'unit_price' => 4000.0]]);

        new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $answered = new AnswerQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id'], accepted: true);

        $this->assertTrue($answered['recorded']);
        $this->assertSame(QuoteStatusEnum::ACCEPTED->value, $answered['status']);

        $converted = new ConvertQuoteToInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertTrue($converted['converted']);
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT->value, $converted['invoice_status']);
        $this->assertSame(4000.0, (float) $converted['total']);

        $invoice = Invoice::getById($converted['invoice_id']);
        $this->assertSame(4000.0, (float) $invoice->total_native);
        $this->assertSame(QuoteStatusEnum::CONVERTED, Quote::getById($created['quote_id'])->status);
    }

    public function test_an_unaccepted_quote_cannot_be_converted(): void
    {
        $this->seedTestOrganization('Premature Corp');
        $created = $this->createQuote('Premature Corp', [['description' => 'Project', 'unit_price' => 900.0]]);

        $converted = new ConvertQuoteToInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertFalse($converted['converted']);
        $this->assertSame('invalid_transition', $converted['reason']);
    }

    public function test_rejecting_a_quote_records_the_reason(): void
    {
        $this->seedTestOrganization('Said No Inc');
        $created = $this->createQuote('Said No Inc', [['description' => 'Project', 'unit_price' => 300.0]]);

        new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $answered = new AnswerQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id'], accepted: false, reason: 'Went with a cheaper vendor');

        $this->assertTrue($answered['recorded']);
        $this->assertSame(QuoteStatusEnum::REJECTED->value, $answered['status']);
        $this->assertSame('Went with a cheaper vendor', Quote::getById($created['quote_id'])->lost_reason);
    }

    public function test_find_quote_returns_the_id_the_other_tools_need(): void
    {
        $this->seedTestOrganization('Findable SA');
        $created = $this->createQuote('Findable SA', [['description' => 'Work', 'unit_price' => 120.0]]);

        $sent = new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $found = new FindQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_number: (string) $sent['quote_number']);

        $this->assertTrue($found['found']);
        $this->assertSame($created['quote_id'], $found['quote_id']);
        $this->assertCount(1, $found['lines']);

        $missing = new FindQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_number: 'Q-DOES-NOT-EXIST');
        $this->assertFalse($missing['found']);
    }

    public function test_quote_and_invoice_pdf_tools_attach_the_file(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $customer = $this->seedTestOrganization('Pdf Wanted Co');
        $created = $this->createQuote('Pdf Wanted Co', [['description' => 'Work', 'unit_price' => 640.0]]);

        $quotePdf = new GenerateQuotePdfTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertTrue($quotePdf['generated']);
        $this->assertNotEmpty($quotePdf['file_url']);
        $this->assertNotNull(
            Quote::getById($created['quote_id'])->getFileByName(DocumentPdfService::QUOTE_FIELD_NAME)
        );

        $invoice = $this->issueTestInvoice($customer, subtotal: 640.0);

        $invoicePdf = new GenerateInvoicePdfTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_number: (string) $invoice->invoice_number);

        $this->assertTrue($invoicePdf['generated']);
        $this->assertSame($invoice->getId(), $invoicePdf['document_id']);
    }

    /** An LLM-supplied id must never resolve against the whole platform when no tenant is bound. */
    public function test_quote_tools_fail_closed_without_tenant_context(): void
    {
        $this->seedTestOrganization('Unbound Co');
        $created = $this->createQuote('Unbound Co', [['description' => 'Work', 'unit_price' => 10.0]]);

        $unbound = new SendQuoteTool()->__invoke(quote_id: $created['quote_id']);
        $this->assertFalse($unbound['sent']);
        $this->assertSame('no_tenant_context', $unbound['reason']);

        $unboundPdf = new GenerateInvoicePdfTool()->__invoke(invoice_id: 1);
        $this->assertFalse($unboundPdf['generated']);
        $this->assertSame('no_tenant_context', $unboundPdf['reason']);
    }

    public function test_invoice_pdf_tool_needs_an_identifier(): void
    {
        $result = new GenerateInvoicePdfTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke();

        $this->assertFalse($result['generated']);
        $this->assertSame('identifier_required', $result['reason']);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function createQuote(string $customerName, array $lines): array
    {
        return new CreateQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: $customerName, lines: $lines);
    }
}
