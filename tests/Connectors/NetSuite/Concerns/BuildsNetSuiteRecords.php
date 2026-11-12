<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteInvoiceAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteSalesOrderToOrderAction;
use NetSuite\Classes\Address;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerAddressbook;
use NetSuite\Classes\CustomerAddressbookList;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\Invoice as NetSuiteInvoice;
use NetSuite\Classes\InvoiceItem;
use NetSuite\Classes\InvoiceItemList;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\Record;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\SalesOrderItem;
use NetSuite\Classes\SalesOrderItemList;
use NetSuite\Classes\SearchRequest;
use Override;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;

/**
 * NetSuite records shaped like invoice 2611599 / SO26114211 (The Art Supply Depo, Copic markers), and
 * the wiring that points every NetSuite service an import builds at one in-memory account.
 */
trait BuildsNetSuiteRecords
{
    protected FakeNetSuiteClient $netsuite;

    /** @var array<string, InventoryItem> NetSuite's catalog, by itemId */
    protected array $netsuiteItems = [];

    /** @var array<string, Record> transactions, by tranId */
    protected array $netsuiteTransactions = [];

    protected function fakeNetSuite(Companies $company): void
    {
        $this->netsuite = new FakeNetSuiteClient(app(Apps::class), $company);
        $this->netsuite->soap->searchBy = function (SearchRequest $request): array {
            $basic = $request->searchRecord->basic;
            $found = $request->searchRecord instanceof ItemSearch
                ? $this->netsuiteItems[$basic->itemId->searchValue] ?? null
                : $this->netsuiteTransactions[$basic->tranId->searchValue] ?? null;

            return $found !== null ? [$found] : [];
        };
    }

    protected function wireProductAction(PullNetSuiteProductAction $action): PullNetSuiteProductAction
    {
        $this->netsuite->into(new ReflectionProperty($action, 'productService')->getValue($action));

        // Price/stock refresh is PullNetSuiteProductPriceActionTest's concern; here it must not reach NetSuite.
        $user = auth()->user();
        new ReflectionProperty($action, 'priceRefresh')->setValue(
            $action,
            new class (app(Apps::class), $user->getCurrentCompany(), $user) extends PullNetSuiteProductPriceAction {
                #[Override]
                public function execute(string $barcode): array
                {
                    return [];
                }
            }
        );

        return $action;
    }

    protected function wireSalesOrderAction(PullNetSuiteSalesOrderToOrderAction $action): PullNetSuiteSalesOrderToOrderAction
    {
        $this->netsuite->into(new ReflectionProperty($action, 'salesOrderService')->getValue($action));
        $this->wireProductAction(new ReflectionProperty($action, 'productAction')->getValue($action));

        $customerAction = new ReflectionProperty($action, 'customerAction')->getValue($action);
        $this->netsuite->into(new ReflectionProperty($customerAction, 'service')->getValue($customerAction));

        return $action;
    }

    protected function wireInvoiceAction(PullNetSuiteInvoiceAction $action): PullNetSuiteInvoiceAction
    {
        $this->netsuite->into(new ReflectionProperty($action, 'invoiceService')->getValue($action));
        $this->wireSalesOrderAction(new ReflectionProperty($action, 'salesOrderAction')->getValue($action));

        $customerAction = new ReflectionProperty($action, 'customerAction')->getValue($action);
        $this->netsuite->into(new ReflectionProperty($customerAction, 'service')->getValue($customerAction));

        return $action;
    }

    /**
     * @param list<array{0: string, 1: string, 2: float, 3: float}> $lines [barcode, description, qty, rate]
     */
    protected function netsuiteInvoice(
        string $internalId,
        string $tranId,
        string $customerId,
        string $salesOrderId,
        array $lines,
        ?float $total = null,
        float $paid = 0.0
    ): NetSuiteInvoice {
        $items = array_map(function (array $line): InvoiceItem {
            [$barcode, $description, $quantity, $rate] = $line;
            $item = new InvoiceItem();
            $item->item = new RecordRef();
            $item->item->name = $barcode;
            $item->description = $description;
            $item->quantity = $quantity;
            $item->rate = $rate;
            $item->amount = round($quantity * $rate, 2);

            return $item;
        }, $lines);
        $sum = array_sum(array_map(fn (InvoiceItem $item): float => $item->amount, $items));

        $invoice = new NetSuiteInvoice();
        $invoice->internalId = $internalId;
        $invoice->tranId = $tranId;
        $invoice->tranDate = '2026-06-10T00:00:00.000-07:00';
        $invoice->dueDate = '2026-06-10T00:00:00.000-07:00';
        $invoice->entity = new RecordRef();
        $invoice->entity->internalId = $customerId;
        $invoice->createdFrom = new RecordRef();
        $invoice->createdFrom->internalId = $salesOrderId;
        $invoice->otherRefNum = '3848';
        $invoice->memo = 'IF40262';
        $invoice->currencyName = 'US Dollar';
        $invoice->total = $total ?? $sum;
        $invoice->amountPaid = $paid;
        $invoice->amountRemaining = ($total ?? $sum) - $paid;
        $invoice->itemList = new InvoiceItemList();
        $invoice->itemList->item = $items;
        $this->netsuite->soap->serve(RecordType::invoice, $invoice);
        $this->netsuiteTransactions[$tranId] = $invoice;

        return $invoice;
    }

    protected function netsuiteItem(string $barcode, string $name): InventoryItem
    {
        $item = new InventoryItem();
        $item->internalId = 'ITEM-' . $barcode;
        $item->itemId = $barcode;
        $item->displayName = $name;
        $item->isInactive = false;
        $this->netsuiteItems[$barcode] = $item;
        $this->netsuite->soap->serve(RecordType::inventoryItem, $item);

        return $item;
    }

    protected function netsuiteCustomer(string $internalId, string $email): Customer
    {
        $address = new Address();
        $address->attention = 'Julie Lynn';
        $address->addr1 = '5739 Main Street';
        $address->city = 'Sylvania';
        $address->state = 'OH';
        $address->zip = '43560';

        $entry = new CustomerAddressbook();
        $entry->defaultBilling = true;
        $entry->addressbookAddress = $address;

        $customer = new Customer();
        $customer->internalId = $internalId;
        $customer->companyName = 'The Art Supply Depo ' . $internalId;
        $customer->email = $email;
        $customer->addressbookList = new CustomerAddressbookList();
        $customer->addressbookList->addressbook = [$entry];
        $this->netsuite->soap->serve(RecordType::customer, $customer);

        return $customer;
    }

    /**
     * @param list<array{0: string, 1: string, 2: float, 3: float}> $lines [barcode, description, qty, rate]
     */
    protected function netsuiteSalesOrder(string $internalId, string $customerId, array $lines): SalesOrder
    {
        $items = array_map(function (array $line): SalesOrderItem {
            [$barcode, $description, $quantity, $rate] = $line;
            $item = new SalesOrderItem();
            $item->item = new RecordRef();
            $item->item->name = $barcode;
            $item->description = $description;
            $item->quantity = $quantity;
            $item->rate = $rate;
            $item->amount = round($quantity * $rate, 2);

            return $item;
        }, $lines);

        $subTotal = array_sum(array_map(fn (SalesOrderItem $item): float => $item->amount, $items));

        $order = new SalesOrder();
        $order->internalId = $internalId;
        $order->tranId = 'SO' . $internalId;
        $order->entity = new RecordRef();
        $order->entity->internalId = $customerId;
        $order->otherRefNum = '3848';
        $order->memo = 'IF40262';
        $order->status = 'Pending Billing';
        $order->currencyName = 'US Dollar';
        $order->subTotal = $subTotal;
        $order->taxTotal = 0.0;
        $order->shippingCost = 0.0;
        $order->discountTotal = 0.0;
        $order->total = $subTotal;
        $order->itemList = new SalesOrderItemList();
        $order->itemList->item = $items;
        $this->netsuite->soap->serve(RecordType::salesOrder, $order);

        return $order;
    }
}
