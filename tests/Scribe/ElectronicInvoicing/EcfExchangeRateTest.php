<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfDocumentBuilder;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfExchangeRate;
use Kanvas\Scribe\FxRates\Models\FxRate;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;

/**
 * A USD e-CF stays in USD and declares to DGII the DOP rate in force on its issue date (TipoCambio).
 */
class EcfExchangeRateTest extends EcfCompletionTestCase
{
    public function test_a_usd_e_cf_declares_the_banco_central_rate_and_stays_in_usd(): void
    {
        $this->rate('2026-06-15', 60.4617);

        $invoice = $this->issueUsd();

        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(60.4617, EcfCompliance::draftSettings($invoice)[EcfExchangeRate::KEY], 'frozen on the invoice');
        $document = new EcfDocumentBuilder()->build($invoice->refresh());
        $this->assertSame('USD', $document->currency);
        $this->assertSame(60.4617, $document->exchangeRate);
    }

    public function test_without_a_rate_on_file_nothing_is_issued_and_no_number_is_used(): void
    {
        FxRate::query()->where('apps_id', $this->kanvasApp->getId())->where('base_currency', 'USD')->delete();
        $draft = $this->usdDraft();

        try {
            $this->issue($draft);
            $this->fail('A USD e-CF without a rate must not be issued.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no official USD→DOP rate on file', $e->getMessage());
        }

        $this->assertNull(EcfCompliance::current($draft->refresh()), 'no e-NCF was spent');
    }

    public function test_a_credit_note_declares_its_invoices_rate(): void
    {
        $this->rate('2026-06-15', 60.4617);
        $invoice = $this->issueUsd();
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();
        $this->rate(Carbon::today()->toDateString(), 63.0);

        $note = new IssueCreditNoteAction(
            parentInvoice: $invoice->refresh(),
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $invoice->customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Ajuste',
                        quantity: 1.0,
                        unit_price_native: 100.0,
                        tax_rate: 18.0,
                        tax_amount_native: 18.0
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            billable: $invoice->customer,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(60.4617, EcfExchangeRate::declared($note), 'the same peso value the invoice declared');
    }

    public function test_a_peso_e_cf_declares_one(): void
    {
        $invoice = $this->issue($this->draftInvoice($this->customer()));

        $this->assertSame(1.0, EcfExchangeRate::declared($invoice));
        $this->assertArrayNotHasKey(EcfExchangeRate::KEY, EcfCompliance::draftSettings($invoice));
    }

    private function rate(string $date, float $rate): void
    {
        FxRate::query()->updateOrCreate(
            ['apps_id' => $this->kanvasApp->getId(), 'base_currency' => 'USD', 'quote_currency' => 'DOP', 'rate_date' => $date],
            ['rate' => $rate, 'source' => 'manual']
        );
    }

    private function usdDraft(): Invoice
    {
        $draft = $this->draftInvoice($this->customer());
        $draft->currency = 'USD';
        $draft->saveOrFail();

        return $draft;
    }

    private function issueUsd(): Invoice
    {
        return $this->issue($this->usdDraft());
    }

    private function issue(Invoice $draft): Invoice
    {
        return new IssueInvoiceAction(invoice: $draft, billable: $draft->customer, user: static::$cachedUser)->execute();
    }
}
