<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AnswerQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConvertQuoteToInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateInvoicePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuotePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuoteSowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendQuoteTool;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
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

    public function test_create_quote_records_the_contact_the_quote_is_addressed_to(): void
    {
        $customer = $this->seedTestOrganization('Contactful Industries');
        $contact = $this->seedOrganizationContact($customer, 'Jane', 'Ashford');

        $result = $this->createQuote(
            'Contactful Industries',
            [['description' => 'Advisory', 'unit_price' => 900.0]],
            'Jane Ashford',
        );

        $this->assertTrue($result['created']);
        $this->assertSame('Jane Ashford', $result['contact']);

        $quote = Quote::getById($result['quote_id']);
        $this->assertSame($contact->getId(), $quote->contact_people_id);

        $sent = $this->markSent($quote->getId());

        $found = new FindQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_number: (string) $sent->quote_number);

        $this->assertTrue($found['found']);
        $this->assertSame('Jane Ashford', $found['contact'], 'find_quote must report who the quote is addressed to.');

        $this->assertStringContainsString(
            'Attn: Jane Ashford',
            new DocumentPdfService($quote->refresh())->render(),
        );
    }

    public function test_create_quote_leaves_the_contact_null_when_none_is_given(): void
    {
        $this->seedTestOrganization('Anonymous Holdings');

        $result = $this->createQuote('Anonymous Holdings', [['description' => 'Advisory', 'unit_price' => 100.0]]);

        $this->assertTrue($result['created']);
        $this->assertNull($result['contact']);
        $this->assertNull(Quote::getById($result['quote_id'])->contact_people_id);
    }

    public function test_create_quote_refuses_a_contact_that_is_not_linked_to_that_customer(): void
    {
        $customer = $this->seedTestOrganization('Strict Corp');
        $otherCustomer = $this->seedTestOrganization('Somewhere Else LLC');
        $this->seedOrganizationContact($otherCustomer, 'Mallory', 'Quinn');

        $result = $this->createQuote(
            'Strict Corp',
            [['description' => 'Advisory', 'unit_price' => 100.0]],
            'Mallory Quinn',
        );

        $this->assertFalse($result['created']);
        $this->assertSame('contact_not_found', $result['reason']);
        $this->assertSame(
            0,
            Quote::query()->fromApp($this->kanvasApp)->fromCompany($this->company)
                ->where('customer_organization_id', $customer->getId())
                ->count(),
            'A refused contact must not leave a quote behind.',
        );
    }

    /**
     * organizations_peoples is a raw-FK pivot with no tenant columns of its own, so a row on it can
     * point at a person outside the tenant. The org being tenant-scoped is not enough on its own.
     */
    public function test_create_quote_ignores_an_organization_contact_from_another_tenant(): void
    {
        $customer = $this->seedTestOrganization('Leaky Pivot Corp');
        $foreign = $this->seedOrganizationContact($customer, 'Otra', 'Empresa');
        $foreign->companies_id = $this->company->getId() + 1;
        $foreign->saveQuietly();

        $result = $this->createQuote(
            'Leaky Pivot Corp',
            [['description' => 'Advisory', 'unit_price' => 100.0]],
            'Otra Empresa',
        );

        $this->assertFalse($result['created']);
        $this->assertSame('contact_not_found', $result['reason']);
    }

    public function test_create_quote_names_the_candidates_when_the_contact_is_ambiguous(): void
    {
        $customer = $this->seedTestOrganization('Twin Contacts Inc');
        $this->seedOrganizationContact($customer, 'Chris', 'Delacroix');
        $this->seedOrganizationContact($customer, 'Chris', 'Delagarza');

        $result = $this->createQuote(
            'Twin Contacts Inc',
            [['description' => 'Advisory', 'unit_price' => 100.0]],
            'Chris Dela',
        );

        $this->assertFalse($result['created']);
        $this->assertSame('contact_ambiguous', $result['reason']);
        $this->assertStringContainsString('Chris Delacroix', $result['message']);
        $this->assertStringContainsString('Chris Delagarza', $result['message']);
    }

    public function test_create_quote_needs_at_least_one_priced_line(): void
    {
        $this->seedTestOrganization('Lineless Corp');

        $result = $this->createQuote('Lineless Corp', [['description' => 'No price given']]);

        $this->assertFalse($result['created']);
        $this->assertSame('lines_required', $result['reason']);
    }

    /**
     * send_quote now delivers (Track K): with nobody to deliver to it asks instead of flipping the status,
     * so the quote stays an unnumbered draft. Delivery itself is covered in tests/Scribe/Delivery.
     */
    public function test_send_quote_without_a_known_contact_asks_and_leaves_the_draft_unsent(): void
    {
        $this->seedTestOrganization('Sendable Inc');
        $created = $this->createQuote('Sendable Inc', [['description' => 'Work', 'unit_price' => 750.0]]);

        $sent = new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertFalse($sent['sent']);
        $this->assertSame('no_billing_contact', $sent['reason']);
        $quote = Quote::getById($created['quote_id']);
        $this->assertSame(QuoteStatusEnum::DRAFT, $quote->status);
        $this->assertNull($quote->quote_number);
    }

    public function test_send_quote_on_a_deleted_customer_reports_it_instead_of_throwing(): void
    {
        $organization = $this->seedTestOrganization('Gone Co');
        $created = $this->createQuote('Gone Co', [['description' => 'Work', 'unit_price' => 100.0]]);
        $organization->softDelete();

        $sent = new SendQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertFalse($sent['sent']);
        $this->assertSame('customer_not_found', $sent['reason']);
        $this->assertSame(QuoteStatusEnum::DRAFT, Quote::getById($created['quote_id'])->status);
    }

    /** A model that repeats itself must not renumber the quote the customer already has. */
    public function test_sending_the_same_quote_twice_keeps_the_first_number(): void
    {
        $this->seedTestOrganization('Double Send Co');
        $created = $this->createQuote('Double Send Co', [['description' => 'Work', 'unit_price' => 100.0]]);

        $first = $this->markSent($created['quote_id']);
        $second = $this->markSent($created['quote_id']);

        $this->assertSame(QuoteStatusEnum::SENT, $second->status);
        $this->assertNotEmpty($first->quote_number);
        $this->assertSame($first->quote_number, $second->quote_number);
    }

    public function test_quote_travels_from_draft_to_a_draft_invoice(): void
    {
        $this->seedTestOrganization('Full Journey Ltd');
        $created = $this->createQuote('Full Journey Ltd', [['description' => 'Project', 'unit_price' => 4000.0]]);

        $this->markSent($created['quote_id']);

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
        $this->assertSame($invoice->pendingApproval() !== null, $converted['pending_approval'], 'the draft reaches an approver when a policy applies');
        $this->assertSame($created['quote_id'], (int) $invoice->quote_id);
        $this->assertSame(Quote::getById($created['quote_id'])->lines()->count(), $invoice->lines()->count(), 'line by line');
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
        $this->assertSame('draft', $converted['quote_status']);
        $this->assertStringContainsString('answer_quote', $converted['message']);
        $this->assertStringContainsString('Do not rebuild the quote with create_ar_invoice', $converted['message']);
    }

    public function test_a_never_sent_quote_the_customer_accepted_outside_kanvas_is_invoiced_from_the_quote(): void
    {
        $this->seedTestOrganization('Offline Yes Ltd');
        $created = $this->createQuote('Offline Yes Ltd', [['description' => 'Project', 'unit_price' => 700.0]]);
        $answer = new AnswerQuoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $refused = $answer->__invoke(quote_id: $created['quote_id'], accepted: true);
        $this->assertFalse($refused['recorded']);
        $this->assertSame('never_sent', $refused['reason']);
        $this->assertSame(QuoteStatusEnum::DRAFT, Quote::getById($created['quote_id'])->status);

        $answered = $answer->__invoke(quote_id: $created['quote_id'], accepted: true, accepted_outside_kanvas: true);
        $this->assertTrue($answered['recorded']);
        $this->assertSame(QuoteStatusEnum::ACCEPTED->value, $answered['status']);
        $this->assertNotNull($answered['quote_number'], 'numbered as a sent quote is');

        $converted = new ConvertQuoteToInvoiceTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $created['quote_id']);

        $this->assertTrue($converted['converted']);
        $this->assertSame($created['quote_id'], (int) Invoice::getById($converted['invoice_id'])->quote_id);
    }

    public function test_create_ar_invoice_refuses_to_rebuild_an_open_quote(): void
    {
        $this->seedTestOrganization('Rebuild Corp');
        $created = $this->createQuote('Rebuild Corp', [['description' => 'Design', 'unit_price' => 950.0]]);
        $invoice = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $sameTotal = $invoice->__invoke(customer_name: 'Rebuild Corp', memo: 'Design work', amount: 950.0);
        $this->assertFalse($sameTotal['created']);
        $this->assertSame('invoice_from_quote', $sameTotal['reason']);
        $this->assertSame($created['quote_id'], $sameTotal['quote_id']);
        $this->assertStringContainsString('accepted_outside_kanvas', $sameTotal['message']);

        $namedInMemo = $invoice->__invoke(customer_name: 'Rebuild Corp', memo: 'Quote 12 conversion', amount: 400.0);
        $this->assertSame('invoice_from_quote', $namedInMemo['reason']);

        $unrelated = $invoice->__invoke(customer_name: 'Rebuild Corp', memo: 'Hosting renewal', amount: 120.0);
        $this->assertTrue($unrelated['created'], 'other work for a customer with an open quote is still invoiced');
    }

    /**
     * The agent once hand-built the 50% deposit of a quote whose SOW schedule would have billed it on acceptance: the
     * e-CF went out with no quote_id and the quote stayed a draft.
     */
    public function test_create_ar_invoice_refuses_to_hand_build_a_deposit_or_a_line_of_an_open_quote(): void
    {
        $this->seedTestOrganization('Cresa Clinic');
        $created = $this->createQuote('Cresa Clinic', [
            ['description' => 'Patient UI implementation', 'unit_price' => 15000.0, 'tax_amount' => 2700.0],
            ['description' => 'Monthly cloud operations', 'unit_price' => 2500.0, 'tax_amount' => 450.0],
        ]);
        $quote = Quote::getById($created['quote_id']);
        $sow = new GenerateQuoteSowTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)->__invoke(
            quote_id: $quote->getId(),
            summary: 'Patient portal on Kanvas.',
            scope: ['Patient UI', 'Cloud operations'],
            deliverables: [[
                'name' => 'Portal',
                'acceptance_criteria' => 'Live.',
                'quote_line_ids' => $quote->lines->pluck('id')->all(),
            ]],
            timeline_start: '2026-11-09',
            timeline_end: '2027-01-31',
            payment_schedule: [
                ['label' => 'Upfront implementation deposit (50%)', 'when' => 'on_signature', 'amount' => 8850],
                ['label' => 'Implementation balance on handover', 'when' => 'date', 'date' => '2026-12-31', 'amount' => 8850],
                ['label' => 'First month cloud retainer', 'when' => 'date', 'date' => '2027-01-01', 'amount' => 2950],
            ],
            signatories: [['name' => 'Jane Customer', 'role' => 'Director']],
            render_pdf: false,
        );
        $this->assertTrue($sow['saved'], json_encode($sow));
        $invoice = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $deposit = $invoice->__invoke(
            customer_name: 'Cresa Clinic',
            memo: 'New UI implementation',
            lines: [['description' => 'New UI implementation - first payment', 'unit_price' => 7500.0, 'tax_rate' => 18]],
        );
        $this->assertFalse($deposit['created'], 'the SOW schedule bills this amount on acceptance');
        $this->assertSame('invoice_from_quote', $deposit['reason']);
        $this->assertSame($quote->getId(), $deposit['quote_id']);
        $this->assertStringContainsString('accepted_outside_kanvas', $deposit['message']);
        $this->assertStringContainsString('payment schedule', $deposit['message']);

        $namedDeposit = $invoice->__invoke(
            customer_name: 'Cresa Clinic',
            memo: 'Portal work',
            lines: [['description' => '50% deposit', 'unit_price' => 100.0, 'tax_rate' => 18]],
        );
        $this->assertSame('invoice_from_quote', $namedDeposit['reason'], 'a deposit of anything is a part of a quote');

        $oneLine = $invoice->__invoke(
            customer_name: 'Cresa Clinic',
            memo: 'Cloud operations, first month',
            lines: [['description' => 'Cloud operations', 'unit_price' => 2500.0, 'tax_rate' => 18]],
        );
        $this->assertSame('invoice_from_quote', $oneLine['reason'], 'one quote line is still quoted work');

        $unrelated = $invoice->__invoke(
            customer_name: 'Cresa Clinic',
            memo: 'Hosting renewal',
            lines: [['description' => 'Hosting renewal', 'unit_price' => 120.0, 'tax_rate' => 18]],
        );
        $this->assertTrue($unrelated['created'], 'other work for the customer is still invoiced');
        $this->assertSame(0, Invoice::query()->where('quote_id', $quote->getId())->count(), 'nothing was hung off the draft quote');
    }

    public function test_create_ar_invoice_refuses_a_second_invoice_for_the_same_open_amount(): void
    {
        $this->seedTestOrganization('Twice Billed Co');
        $invoice = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $first = $invoice->__invoke(customer_name: 'Twice Billed Co', memo: 'October services', amount: 1250.0);
        $this->assertTrue($first['created']);

        $second = $invoice->__invoke(customer_name: 'Twice Billed Co', memo: 'October services again', amount: 1250.0);
        $this->assertFalse($second['created']);
        $this->assertSame('duplicate_invoice', $second['reason']);
        $this->assertSame($first['invoice_id'], $second['invoice_id']);
        $this->assertStringContainsString('bills the customer twice', $second['message']);

        $other = $invoice->__invoke(customer_name: 'Twice Billed Co', memo: 'Hosting', amount: 80.0);
        $this->assertTrue($other['created'], 'a different amount is other work');
    }

    public function test_rejecting_a_quote_records_the_reason(): void
    {
        $this->seedTestOrganization('Said No Inc');
        $created = $this->createQuote('Said No Inc', [['description' => 'Project', 'unit_price' => 300.0]]);

        $this->markSent($created['quote_id']);

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

        $sent = $this->markSent($created['quote_id']);

        $found = new FindQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_number: (string) $sent->quote_number);

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
     * SENT as a precondition: the status-only path the UI's "mark as sent" uses, no delivery.
     */
    private function markSent(int $quoteId): Quote
    {
        $quote = Quote::getById($quoteId);

        return new SendQuoteAction(quote: $quote, billable: $quote->customer, user: static::$cachedUser)->execute();
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function createQuote(string $customerName, array $lines, ?string $contactName = null): array
    {
        return new CreateQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: $customerName, lines: $lines, contact_name: $contactName);
    }

    private function seedOrganizationContact(Organization $organization, string $firstName, string $lastName): People
    {
        $person = People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => trim($firstName . ' ' . $lastName),
            'firstname' => $firstName,
            'lastname' => $lastName,
        ]);

        OrganizationPeople::create([
            'organizations_id' => $organization->getId(),
            'peoples_id' => $person->getId(),
            'created_at' => now(),
        ]);

        return $person;
    }
}
