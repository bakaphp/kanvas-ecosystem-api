<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\ImportInvoiceFromExternalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Souk\Orders\Models\Order;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * B2B: an order is what was bought, an invoice is what is owed for it, and one order can carry several
 * invoices. The link is a real column on the invoice, readable from both sides and through GraphQL.
 */
class InvoiceOrderLinkTest extends ScribeTestCase
{
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'commerce'];

    private function order(): Order
    {
        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();
    }

    private function invoiceData(?Order $order, string $externalId, float $amount = 100.0): InvoiceData
    {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $this->seedTestOrganization('The Art Supply Depo'),
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(description: 'Copic Sketch Marker', quantity: 1, unit_price_native: $amount),
            ]),
            currency: 'USD',
            fx_rate_to_base: 1.0,
            invoice_number: $externalId,
            issued_date: Carbon::parse('2026-06-10'),
            due_date: Carbon::parse('2026-06-10'),
            order: $order,
            source: 'netsuite',
            external_id: $externalId,
            origin: JournalEntryOriginEnum::EXTERNAL,
        );
    }

    public function test_an_invoice_created_with_an_order_is_readable_from_both_sides(): void
    {
        $order = $this->order();

        $invoice = new CreateInvoiceAction(
            data: $this->invoiceData($order, 'INV-1'),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($order->getId(), $invoice->order_id);
        $this->assertSame($order->getId(), $invoice->order->getId());
        $this->assertSame([$invoice->getId()], $order->invoices()->pluck('id')->all());
    }

    public function test_one_order_carries_several_imported_invoices(): void
    {
        $order = $this->order();

        $first = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData($order, 'INV-SHIP-1', 120.0),
            user: static::$cachedUser,
        )->execute();
        $second = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData($order, 'INV-SHIP-2', 47.0),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($order->getId(), $first->order_id);
        $this->assertSame($order->getId(), $second->order_id);
        $this->assertEqualsCanonicalizing(
            [$first->getId(), $second->getId()],
            $order->invoices()->pluck('id')->all()
        );
    }

    public function test_an_invoice_without_an_order_has_no_link(): void
    {
        $invoice = new CreateInvoiceAction(
            data: $this->invoiceData(null, 'INV-STANDALONE'),
            user: static::$cachedUser,
        )->execute();

        $this->assertNull($invoice->order_id);
        $this->assertNull($invoice->order);
    }

    public function test_graphql_exposes_the_order_on_the_invoice_and_the_invoices_on_the_order(): void
    {
        $order = $this->order();
        $invoice = new CreateInvoiceAction(
            data: $this->invoiceData($order, 'INV-GQL'),
            user: static::$cachedUser,
        )->execute();

        $this->graphQL('
            query($where: QueryScribeInvoicesWhereWhereConditions) {
                scribeInvoices(where: $where) { data { id order { id invoices { id } } } }
            }
        ', ['where' => ['column' => 'EXTERNAL_ID', 'value' => 'INV-GQL']])
        ->assertJson([
            'data' => [
                'scribeInvoices' => [
                    'data' => [[
                        'id' => (string) $invoice->getId(),
                        'order' => [
                            'id' => (string) $order->getId(),
                            'invoices' => [['id' => (string) $invoice->getId()]],
                        ],
                    ]],
                ],
            ],
        ]);

        $this->assertInstanceOf(
            Invoice::class,
            Invoice::query()->where('order_id', $order->getId())->first()
        );
    }
}
