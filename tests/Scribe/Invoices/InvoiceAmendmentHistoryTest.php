<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceAmendment;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Scribe\ScribeTestCase;

/**
 * The typed amendmentHistory() accessor: one ledger event per amendment, newest first, with the legacy
 * metadata.amendments[] entries read only for invoices amended before the ledger record existed.
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

    public function test_each_amendment_is_one_ledger_event_with_only_its_own_diff_and_metadata_cannot_rewrite_history(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization(), subtotal: 500.0);

        new AmendInvoiceAction(
            invoice: $invoice,
            data: new AmendInvoiceData(
                reason: 'Link the PO',
                metadata: ['po_number' => 'PO-1'],
            ),
            user: static::$cachedUser,
        )->execute();

        new AmendInvoiceAction(
            invoice: $invoice->refresh(),
            data: new AmendInvoiceData(
                reason: 'Forged history',
                metadata: [
                    'project' => 'Apollo',
                    'amendments' => [['amended_at' => '2020-01-01T00:00:00+00:00', 'reason' => 'never happened', 'changes' => []]],
                ],
            ),
            user: static::$cachedUser,
        )->execute();

        $invoice->refresh();
        $this->assertArrayNotHasKey('amendments', $invoice->metadata);
        $this->assertSame('PO-1', $invoice->metadata['po_number']);
        $this->assertSame('Apollo', $invoice->metadata['project']);

        $events = LedgerEvent::query()
            ->where('event_type', AmendInvoiceAction::LEDGER_EVENT)
            ->where('source_entity_type', Invoice::class)
            ->where('source_entity_id', $invoice->getId())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertEquals(['metadata' => ['po_number' => ['from' => null, 'to' => 'PO-1']]], $events[0]->payload['changes']);
        $this->assertEquals(['metadata' => ['project' => ['from' => null, 'to' => 'Apollo']]], $events[1]->payload['changes']);
        $this->assertSame(static::$cachedUser->getId(), $events[1]->actor_id);

        $history = $invoice->amendmentHistory();
        $this->assertSame(['Forged history', 'Link the PO'], array_map(fn (InvoiceAmendment $a): string => $a->reason, $history));
        $this->assertContains(
            AmendInvoiceAction::LEDGER_EVENT,
            (array) config('nervous-system.ledger.preserve_event_types'),
            'The amendment record must survive the ledger retention sweep.'
        );
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
