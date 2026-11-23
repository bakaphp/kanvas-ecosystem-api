<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Services\NetSuiteInvoiceService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteSalesOrderService;
use Kanvas\Exceptions\ModelNotFoundException;
use NetSuite\Classes\Invoice;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\TransactionType;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\TestCase;

class NetSuiteInvoiceServiceTest extends TestCase
{
    private FakeNetSuiteClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeNetSuiteClient(app(Apps::class), auth()->user()->getCurrentCompany());
    }

    private function invoiceService(): NetSuiteInvoiceService
    {
        $app = app(Apps::class);

        return $this->client->into(new NetSuiteInvoiceService($app, auth()->user()->getCurrentCompany()));
    }

    private function invoice(string $internalId, string $tranId): Invoice
    {
        $invoice = new Invoice();
        $invoice->internalId = $internalId;
        $invoice->tranId = $tranId;

        return $invoice;
    }

    public function testAnInvoiceIsFoundByNumberAndTheSearchOnlyMatchesInvoices(): void
    {
        $this->client->soap->searchResults = [$this->invoice('1986059', '2611599')];
        $this->client->soap->serve(RecordType::invoice, $this->invoice('1986059', '2611599'));

        $invoice = $this->invoiceService()->getInvoiceByNumber('2611599');

        $this->assertSame('1986059', $invoice->internalId);
        $basic = $this->client->soap->lastSearch->searchRecord->basic;
        $this->assertSame('2611599', $basic->tranId->searchValue);
        $this->assertSame([TransactionType::_invoice], $basic->type->searchValue);
    }

    public function testAnUnknownInvoiceNumberFailsClearly(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('NetSuite invoice 999 not found');

        $this->invoiceService()->getInvoiceByNumber('999');
    }

    public function testAMissingRecordKeepsTheErrorRetrievingShape(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('Error retrieving invoice: That record does not exist.');

        $this->invoiceService()->getInvoiceById('404');
    }

    public function testASalesOrderIsFetchedByInternalId(): void
    {
        $order = new SalesOrder();
        $order->internalId = '55';
        $order->tranId = 'SO26114211';
        $this->client->soap->serve(RecordType::salesOrder, $order);

        $service = $this->client->into(
            new NetSuiteSalesOrderService(app(Apps::class), auth()->user()->getCurrentCompany())
        );

        $this->assertSame('SO26114211', $service->getSalesOrderById('55')->tranId);
        $this->assertSame(['salesOrder:55'], $this->client->soap->gets);
    }
}
