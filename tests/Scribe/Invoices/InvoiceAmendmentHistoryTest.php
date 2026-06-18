<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceAmendment;
use Tests\Scribe\ScribeTestCase;

/**
 * Verifies the typed amendmentHistory() accessor that exposes metadata.amendments[] without
 * forcing operator UIs to hand-parse JSON.
 */
class InvoiceAmendmentHistoryTest extends ScribeTestCase
{
    public function test_amendment_history_empty_on_new_invoice(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization(), subtotal: 500.0);

        $this->assertSame([], $invoice->amendmentHistory());
        $this->assertFalse($invoice->hasBeenAmended());
    }

    public function test_amendment_history_returns_typed_entries_newest_first(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization(), subtotal: 500.0);

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
        $invoice = $this->issueTestInvoice($this->seedTestOrganization(), subtotal: 500.0);
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
}
