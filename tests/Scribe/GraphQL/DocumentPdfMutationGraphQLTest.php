<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use Kanvas\Scribe\Quotes\Models\Quote;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class DocumentPdfMutationGraphQLTest extends ScribeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }
    }

    public function test_generate_quote_pdf_mutation_returns_the_attached_file(): void
    {
        $customer = $this->seedTestOrganization('Graph Quote Co');

        $quote = new CreateQuoteAction(
            data: new QuoteData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(QuoteLineData::class, [
                    new QuoteLineData(
                        description: 'Discovery workshop',
                        quantity: 1.0,
                        unit_price_native: 1800.0,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-12'),
            ),
            user: static::$cachedUser,
        )->execute();

        $this->graphQL('
            mutation($id: ID!) {
                generateScribeQuotePdf(id: $id) {
                    id
                    name
                    url
                }
            }
        ', ['id' => $quote->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.generateScribeQuotePdf.name', 'quote-draft-' . $quote->getId() . '.pdf');

        $this->assertNotNull(
            Quote::getById($quote->getId())->getFileByName(DocumentPdfService::QUOTE_FIELD_NAME)
        );
    }

    public function test_generate_invoice_pdf_mutation_returns_the_attached_file(): void
    {
        $customer = $this->seedTestOrganization('Graph Invoice Co');
        $invoice = $this->issueTestInvoice($customer, subtotal: 900.0);

        $this->graphQL('
            mutation($id: ID!) {
                generateScribeInvoicePdf(id: $id) {
                    id
                    url
                }
            }
        ', ['id' => $invoice->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.generateScribeInvoicePdf.id', fn (mixed $id): bool => (int) $id > 0);

        $this->assertNotNull($invoice->getFileByName(DocumentPdfService::INVOICE_FIELD_NAME));
    }
}
