<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Actions\AcceptSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\CheckShippingDocumentsAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\RecordShipmentAction;
use Kanvas\Scribe\SalesOrders\Actions\RegisterCustomerPoAction;
use Kanvas\Scribe\SalesOrders\Actions\ReviewSalesOrderAction;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Spatie\LaravelData\DataCollection;

/**
 * A sales order Operations has accepted, and an invoice to ship it against. Needs the helpers of ScribeTestCase.
 */
trait CreatesTestSalesOrder
{
    use CreatesTestQuote;

    /** The products of the test quote, in line order: an invoice has to name the same ones to match its order. */
    private const array QUOTED_PRODUCTS = [
        ['sku' => 'RICE-1', 'description' => 'Pallet of rice'],
        ['sku' => 'BEAN-1', 'description' => 'Pallet of beans'],
    ];

    protected function createReviewedSalesOrder(Organization $customer, int $paymentTermsDays = 50): SalesOrder
    {
        $quote = $this->createTestQuote($customer);

        $order = new RegisterCustomerPoAction(
            SalesOrderData::from(
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                [
                    'customer_id' => $customer->getId(),
                    'po_source' => 'customer_po',
                    'quote_id' => $quote->getId(),
                    'customer_po_number' => 'PO-' . uniqid(),
                    'customer_po_date' => '2026-11-20',
                    'payment_terms_days' => $paymentTermsDays,
                    'signature_checklist' => ['authorized_signer' => true],
                ]
            )
        )->execute();

        return new ReviewSalesOrderAction($order)->execute();
    }

    protected function createAcceptedSalesOrder(Organization $customer, int $paymentTermsDays = 50): SalesOrder
    {
        $order = new ConfirmSalesOrderAction(
            $this->createReviewedSalesOrder($customer, $paymentTermsDays),
            feasibilityConfirmed: true
        )->execute();

        return new AcceptSalesOrderAction($order, Carbon::parse('2026-12-10'), Carbon::parse('2026-12-28'))
            ->execute();
    }

    protected function createShippedSalesOrder(Organization $customer): SalesOrder
    {
        $order = $this->createAcceptedSalesOrder($customer);
        $invoice = $this->issueInvoiceWithLines($customer, [
            ['quantity' => 10, 'unit_price' => 20],
            ['quantity' => 5, 'unit_price' => 30],
        ]);

        $order = new CheckShippingDocumentsAction(
            $order,
            $invoice,
            [['quantity' => 10], ['quantity' => 5]],
            ['total_quantity' => 15]
        )->execute();

        new RecordShipmentAction(
            order: $order,
            blNumber: 'BL-' . uniqid(),
            blDate: Carbon::parse('2026-12-10'),
            user: static::$cachedUser,
        )->execute();

        return $order->fresh();
    }

    protected function draftInvoiceFor(SalesOrder $order, string $currency = 'USD'): Invoice
    {
        return new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $order->customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Pallet of rice',
                        quantity: 10,
                        unit_price_native: 20,
                        sku: 'RICE-1'
                    ),
                    new InvoiceLineData(
                        description: 'Pallet of beans',
                        quantity: 5,
                        unit_price_native: 30,
                        sku: 'BEAN-1'
                    ),
                ]),
                currency: $currency,
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    protected function issueDraftInvoice(Invoice $draft): Invoice
    {
        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $draft->customer,
            user: static::$cachedUser,
        )->execute();
    }

    /**
     * @param list<array{quantity: int|float, unit_price: int|float}> $lines
     */
    protected function issueInvoiceWithLines(Organization $customer, array $lines): Invoice
    {
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(
                    InvoiceLineData::class,
                    array_map(
                        static fn (array $line, int $index): InvoiceLineData => new InvoiceLineData(
                            description: self::QUOTED_PRODUCTS[$index]['description'] ?? 'Shipped goods',
                            quantity: $line['quantity'],
                            unit_price_native: $line['unit_price'],
                            sku: self::QUOTED_PRODUCTS[$index]['sku'] ?? null,
                        ),
                        $lines,
                        array_keys($lines)
                    )
                ),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-12-01'),
                due_date: Carbon::parse('2026-12-31'),
            ),
            user: static::$cachedUser,
        )->execute();

        return $this->issueDraftInvoice($draft);
    }
}
