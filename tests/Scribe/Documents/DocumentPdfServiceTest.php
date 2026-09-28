<?php

declare(strict_types=1);

namespace Tests\Scribe\Documents;

use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Templates\Models\Templates;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class DocumentPdfServiceTest extends ScribeTestCase
{
    public function test_quote_html_carries_the_customer_lines_and_totals(): void
    {
        $customer = $this->seedTestOrganization('Brightstar Foods');
        $quote = new SendQuoteAction(
            quote: $this->draftQuote($customer),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();

        $html = new DocumentPdfService($quote)->render();

        $this->assertStringContainsString('Quote', $html);
        $this->assertStringContainsString((string) $quote->quote_number, $html);
        $this->assertStringContainsString('Brightstar Foods', $html);
        $this->assertStringContainsString('Onboarding + data migration', $html);
        $this->assertStringContainsString('2,500.00', $html);
        $this->assertStringContainsString($this->company->name, $html);
    }

    /**
     * A draft has no billable snapshot — that freezes at send time — so the live customer has to
     * fill the bill-to block, otherwise every draft prints addressed to nobody.
     */
    public function test_draft_quote_falls_back_to_the_live_customer_for_the_bill_to_block(): void
    {
        $customer = $this->seedTestOrganization('Draft Stage Corp');
        $quote = $this->draftQuote($customer);

        $this->assertNull($quote->billable_display_name);
        $this->assertStringContainsString('Draft Stage Corp', new DocumentPdfService($quote)->render());
    }

    public function test_invoice_html_shows_amount_paid_and_balance_due(): void
    {
        $customer = $this->seedTestOrganization('Paid Partly Inc');
        $invoice = $this->issueTestInvoice($customer, subtotal: 1000.0);
        $invoice->paid_native = 250.0;
        $invoice->balance_due_native = 750.0;
        $invoice->save();

        $html = new DocumentPdfService($invoice->refresh())->render();

        $this->assertStringContainsString('Invoice', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('750.00', $html);
        $this->assertStringContainsString('250.00', $html);
    }

    public function test_a_named_template_replaces_the_packaged_layout(): void
    {
        $customer = $this->seedTestOrganization('Custom Layout LLC');
        $quote = $this->draftQuote($customer);

        $template = Templates::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => 'quote-pdf-test-' . uniqid('', true),
            'template' => 'BRANDED COPY for {{ $customer["name"] }}',
        ]);

        $html = new DocumentPdfService($quote, $template->name)->render();

        $this->assertStringContainsString('BRANDED COPY for Custom Layout LLC', $html);
        $this->assertStringNotContainsString('<table class="lines">', $html);
    }

    public function test_generating_the_pdf_attaches_it_to_the_document(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $customer = $this->seedTestOrganization('Attachment Test Co');
        $quote = $this->draftQuote($customer);

        $file = new DocumentPdfService($quote)->generate(static::$cachedUser);

        $this->assertGreaterThan(0, $file->getId());
        $this->assertNotNull($quote->getFileByName(DocumentPdfService::QUOTE_FIELD_NAME));
    }

    public function test_file_name_is_derived_from_the_document_number(): void
    {
        $customer = $this->seedTestOrganization('Naming Co');
        $draft = $this->draftQuote($customer);

        $this->assertSame('quote-draft-' . $draft->getId() . '.pdf', new DocumentPdfService($draft)->fileName());

        $sent = new SendQuoteAction(quote: $draft, billable: $customer, user: static::$cachedUser)->execute();

        $this->assertSame(
            'quote-' . $sent->quote_number . '.pdf',
            new DocumentPdfService($sent)->fileName()
        );
    }

    public function test_credit_notes_print_as_credit_notes(): void
    {
        $customer = $this->seedTestOrganization('Credit Note Co');
        $invoice = $this->issueTestInvoice($customer, subtotal: 400.0);
        $invoice->document_type = 'credit_note';
        $invoice->save();

        /** @var Invoice $creditNote */
        $creditNote = $invoice->refresh();

        $this->assertStringContainsString('Credit Note', new DocumentPdfService($creditNote)->render());
        $this->assertStringStartsWith('credit-note-', new DocumentPdfService($creditNote)->fileName());
    }

    private function draftQuote(Organization $customer): Quote
    {
        return new CreateQuoteAction(
            data: new QuoteData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(QuoteLineData::class, [
                    new QuoteLineData(
                        description: 'Onboarding + data migration',
                        quantity: 1.0,
                        unit_price_native: 2500.0,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-10'),
                notes: 'Includes two workshops.',
                terms: '50% upfront, net 30.',
            ),
            user: static::$cachedUser,
        )->execute();
    }
}
