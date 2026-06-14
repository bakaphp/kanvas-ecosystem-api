<?php

declare(strict_types=1);

namespace Tests\Scribe\Quotes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\ConvertQuoteToInvoiceAction;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteRevisionAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLineData;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\Invoices\Stubs\StubBillable;
use Tests\TestCase;

/**
 * End-to-end: draft quote → send → accept → convert into draft invoice.
 * Plus revision chain test: parent goes SUPERSEDED when a revision is created.
 */
class ConvertQuoteToInvoiceActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    public function test_full_quote_lifecycle_into_draft_invoice(): void
    {
        $billable = new StubBillable(displayName: 'BrightStar Foods');

        // 1. Create draft quote — 10h × $100 = $1000 + 18% tax = $1180
        $draft = new CreateQuoteAction(
            data: $this->makeQuoteData(
                billable: $billable,
                lines: [
                    new QuoteLineData(
                        description: 'Software consulting',
                        quantity: 10,
                        unit_price_native: 100.00,
                        tax_rate: 0.18,
                        tax_amount_native: 180.00,
                    ),
                ],
                issuedDate: Carbon::parse('2026-06-10'),
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(QuoteStatusEnum::DRAFT, $draft->status);
        $this->assertNull($draft->quote_number, 'No number until Send.');
        $this->assertNull($draft->billable_display_name, 'Snapshot not frozen for draft.');
        $this->assertEquals(1180.0, (float) $draft->total_native);

        // 2. Send — number allocated, snapshot frozen
        $sent = new SendQuoteAction(
            quote: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(QuoteStatusEnum::SENT, $sent->status);
        $this->assertSame('1', $sent->quote_number, 'First quote for the company gets "1".');
        $this->assertSame('BrightStar Foods', $sent->billable_display_name, 'Snapshot frozen.');
        $this->assertNotNull($sent->sent_at);
        $this->assertNotNull($sent->valid_until, 'valid_until defaults to issued + 30 days.');

        // 3. Customer accepts
        $accepted = new AcceptQuoteAction(quote: $sent, user: static::$cachedUser)->execute();
        $this->assertSame(QuoteStatusEnum::ACCEPTED, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);

        // 4. Convert to invoice
        $invoice = new ConvertQuoteToInvoiceAction(
            quote: $accepted,
            netTermsDays: 30,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
        $this->assertEquals(1180.0, (float) $invoice->total_native);
        $this->assertEquals(180.0, (float) $invoice->tax_native);
        $this->assertSame($accepted->id, $invoice->quote_id, 'Invoice links back to source quote.');
        $this->assertSame('organization', $invoice->billable_type);
        $this->assertSame(4711, $invoice->billable_id, 'Billable polymorphic FK copied from quote.');

        $invoice->load('lines');
        $this->assertCount(1, $invoice->lines);
        $this->assertSame('Software consulting', $invoice->lines->first()->description);

        // 5. Source quote flipped to CONVERTED + linked to new invoice
        $accepted->refresh();
        $this->assertSame(QuoteStatusEnum::CONVERTED, $accepted->status);
        $this->assertSame($invoice->id, $accepted->converted_to_invoice_id);
    }

    public function test_revision_chain_supersedes_parent(): void
    {
        $billable = new StubBillable();

        $original = new CreateQuoteAction(
            data: $this->makeQuoteData(
                billable: $billable,
                lines: [
                    new QuoteLineData(
                        description: 'Dashboard build',
                        quantity: 1,
                        unit_price_native: 6000.00,
                    ),
                ],
                issuedDate: Carbon::parse('2026-06-10'),
            ),
            user: static::$cachedUser,
        )->execute();

        // Send so it's in a revisable state
        $original = new SendQuoteAction(
            quote: $original,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();

        // Customer asks for 10% off — create revision
        $revisionData = $this->makeQuoteData(
            billable: $billable,
            lines: [
                new QuoteLineData(
                    description: 'Dashboard build (10% off)',
                    quantity: 1,
                    unit_price_native: 5400.00,
                ),
            ],
            issuedDate: Carbon::parse('2026-06-12'),
        );

        $revision = new CreateQuoteRevisionAction(
            originalQuote: $original,
            newRevisionData: $revisionData,
            user: static::$cachedUser,
        )->execute();

        // Revision is fresh DRAFT
        $this->assertSame(QuoteStatusEnum::DRAFT, $revision->status);
        $this->assertSame($original->id, $revision->parent_quote_id);
        $this->assertSame(2, $revision->revision_number);
        $this->assertEquals(5400.0, (float) $revision->total_native);

        // Original moved to SUPERSEDED
        $original->refresh();
        $this->assertSame(QuoteStatusEnum::SUPERSEDED, $original->status);
    }

    /**
     * @param array<int, QuoteLineData> $lines
     */
    private function makeQuoteData(
        StubBillable $billable,
        array $lines,
        string $currency = 'USD',
        float $fxRate = 1.0,
        ?Carbon $issuedDate = null,
    ): QuoteData {
        return new QuoteData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $billable,
            lines: new DataCollection(QuoteLineData::class, $lines),
            currency: $currency,
            fx_rate_to_base: $fxRate,
            issued_date: $issuedDate,
        );
    }
}
