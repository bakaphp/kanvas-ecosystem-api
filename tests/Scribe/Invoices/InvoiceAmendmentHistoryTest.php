<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceAmendment;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\Invoices\Stubs\StubBillable;
use Tests\Scribe\ScribeTestCase;

/**
 * Verifies the typed amendmentHistory() accessor that exposes metadata.amendments[] without
 * forcing operator UIs to hand-parse JSON.
 */
class InvoiceAmendmentHistoryTest extends ScribeTestCase
{
    public function test_amendment_history_empty_on_new_invoice(): void
    {
        $invoice = $this->issueTestInvoice();

        $this->assertSame([], $invoice->amendmentHistory());
        $this->assertFalse($invoice->hasBeenAmended());
    }

    public function test_amendment_history_returns_typed_entries_newest_first(): void
    {
        $invoice = $this->issueTestInvoice();

        new AmendInvoiceAction(
            invoice: $invoice,
            data: new AmendInvoiceData(
                reason: 'Customer requested extension',
                due_date: Carbon::parse('2026-08-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        new AmendInvoiceAction(
            invoice: $invoice->refresh(),
            data: new AmendInvoiceData(
                reason: 'Add internal note about delay',
                internal_notes: 'Awaiting Q3 procurement approval',
            ),
            user: static::$cachedUser,
        )->execute();

        $invoice->refresh();
        $history = $invoice->amendmentHistory();

        $this->assertCount(2, $history);
        $this->assertContainsOnlyInstancesOf(InvoiceAmendment::class, $history);
        $this->assertTrue($invoice->hasBeenAmended());

        // Newest-first ordering
        $this->assertSame('Add internal note about delay', $history[0]->reason);
        $this->assertSame('Customer requested extension', $history[1]->reason);

        // Field-helper methods
        $this->assertTrue($history[0]->changedField('internal_notes'));
        $this->assertFalse($history[0]->changedField('due_date'));
        $this->assertSame(['internal_notes'], $history[0]->fieldsChanged());

        $this->assertTrue($history[1]->changedField('due_date'));
        $this->assertSame('2026-08-15', $history[1]->changes['due_date']['to']);
    }

    public function test_amendment_history_skips_malformed_entries(): void
    {
        $invoice = $this->issueTestInvoice();
        $invoice->metadata = [
            'amendments' => [
                ['amended_at' => '2026-06-20T10:00:00+00:00', 'reason' => 'real', 'changes' => ['notes' => ['from' => null, 'to' => 'hi']]],
                'not even an array',
                ['amended_at' => 'totally invalid date format', 'reason' => 'will be skipped'],
            ],
        ];
        $invoice->save();
        $invoice->refresh();

        $history = $invoice->amendmentHistory();

        // The valid entry survives; the malformed ones are silently skipped (operator UI doesn't crash)
        $this->assertCount(1, $history);
        $this->assertSame('real', $history[0]->reason);
    }

    private function issueTestInvoice(): Invoice
    {
        $billable = new StubBillable();
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Test', quantity: 1, unit_price_native: 500.0),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-07-15'),
                net_terms_days: 30,
            ),
            user: static::$cachedUser,
        )->execute();

        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();
    }
}
