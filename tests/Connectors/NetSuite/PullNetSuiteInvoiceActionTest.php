<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Support\Str;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteInvoiceAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Tests\Connectors\NetSuite\Concerns\BuildsNetSuiteRecords;
use Tests\Scribe\ScribeTestCase;

/**
 * Invoice 2611599 in miniature: billed from a sales order, unpaid, due on receipt. `inventory` is not
 * transacted (products go through CreateProductAction's own retrying transaction); barcodes are unique.
 */
class PullNetSuiteInvoiceActionTest extends ScribeTestCase
{
    use BuildsNetSuiteRecords;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'social', 'commerce'];

    private string $run;

    protected function afterScribeSetUp(): void
    {
        $this->run = (string) random_int(10000000, 99999999);
        $this->fakeNetSuite($this->company);
        $this->netsuiteCustomer('C' . $this->run, 'julie+' . Str::random(8) . '@artsupplydepo.test');
    }

    private function action(): PullNetSuiteInvoiceAction
    {
        return $this->wireInvoiceAction(new PullNetSuiteInvoiceAction($this->kanvasApp, $this->company, static::$cachedUser));
    }

    /**
     * @param list<array{0: string, 1: string, 2: float, 3: float}> $lines
     */
    private function invoiceFor(array $lines, ?float $total = null, float $paid = 0.0): void
    {
        foreach ($lines as [$barcode, $description]) {
            $this->netsuiteItem($barcode, $description);
        }
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, $lines);
        $this->netsuiteInvoice(
            'INV' . $this->run,
            '26' . $this->run,
            'C' . $this->run,
            'SO' . $this->run,
            $lines,
            $total,
            $paid
        );
    }

    public function testTheInvoiceImportsOneToOneLinkedToItsOrderWithNoJournalEntry(): void
    {
        $this->invoiceFor([
            ['45' . $this->run . '01', 'Copic Ciao 6 Colors Set Portrait', 2, 12.57],
            ['45' . $this->run . '02', 'Copic Sketch Marker Black', 6, 3.14],
        ]);

        $action = $this->action();
        $invoice = $action->executeById('INV' . $this->run);

        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame(JournalEntryOriginEnum::EXTERNAL, $invoice->origin);
        $this->assertSame('netsuite', $invoice->source);
        $this->assertSame('26' . $this->run, $invoice->invoice_number);
        $this->assertEqualsWithDelta(43.98, (float) $invoice->total_native, 0.001);
        $this->assertEqualsWithDelta(43.98, (float) $invoice->balance_due_native, 0.001);
        $this->assertCount(2, $invoice->lines);
        $this->assertSame('3848', $invoice->metadata['po_number']);

        $this->assertNotNull($invoice->order_id);
        $this->assertSame($action->order->getId(), $invoice->order_id);
        $this->assertSame([$invoice->getId()], $action->order->invoices()->pluck('id')->all());
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->getId())
            ->count());
    }

    public function testReimportingAfterAPaymentInNetSuiteRefreshesTheBalanceOnTheSameRow(): void
    {
        $lines = [['45' . $this->run . '03', 'Copic Sketch Marker Moss', 3, 3.14]];
        $this->invoiceFor($lines);
        $first = $this->action()->executeByNumber('26' . $this->run);

        $this->invoiceFor($lines, paid: 9.42);
        $second = $this->action()->executeByNumber('26' . $this->run);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $second->document_status);
        $this->assertSame(1, Invoice::query()->where('external_id', 'INV' . $this->run)->count());
    }

    public function testAnInvoiceWhoseLinesDoNotAddUpToItsTotalIsRefused(): void
    {
        // A header charge Kanvas does not model (handling, say) leaves the lines short of the total.
        $this->invoiceFor([['45' . $this->run . '04', 'Copic Sketch Marker Salad', 3, 3.14]], total: 15.00);

        try {
            $this->action()->executeById('INV' . $this->run);
            $this->fail('An invoice that would not total what NetSuite says must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('the invoice was not imported', $e->getMessage());
        }

        $this->assertSame(0, Invoice::query()->where('external_id', 'INV' . $this->run)->count());
    }
}
