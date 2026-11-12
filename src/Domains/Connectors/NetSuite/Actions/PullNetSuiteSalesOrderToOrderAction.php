<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str as IlluminateStr;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Concerns\SyncsOrderItemsFromNetSuiteLines;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteSalesOrderService;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderData;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemData;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use NetSuite\Classes\SalesOrder;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * A NetSuite sales order as a Souk order, line for line. Every line must resolve to a variant before
 * anything is written (missing products are pulled from NetSuite), and the order's line total must
 * equal NetSuite's — an order that disagrees with its source is refused, never saved.
 */
class PullNetSuiteSalesOrderToOrderAction
{
    use SyncsOrderItemsFromNetSuiteLines;

    /** @var list<string> barcodes this run had to pull from NetSuite into the catalog */
    public array $pulledProducts = [];

    /** @var list<string> barcodes whose deleted product this run brought back and refreshed from NetSuite */
    public array $restoredProducts = [];

    protected NetSuiteSalesOrderService $salesOrderService;
    protected PullNetSuiteProductAction $productAction;
    protected SyncNetSuiteCustomerWithOrganizationAction $customerAction;

    public function __construct(protected Apps $app, protected Companies $company, protected UserInterface $user)
    {
        $this->salesOrderService = new NetSuiteSalesOrderService($app, $company);
        $this->productAction = new PullNetSuiteProductAction($app, $company, $user);
        $this->customerAction = new SyncNetSuiteCustomerWithOrganizationAction($app, $company, $user);
    }

    public function execute(int|string $salesOrderInternalId): Order
    {
        $salesOrder = $this->salesOrderService->getSalesOrderById($salesOrderInternalId);
        $lines = $this->productLines($salesOrder);

        if ($lines === []) {
            throw new ValidationException('NetSuite sales order ' . $salesOrder->tranId . ' has no item lines');
        }

        $variants = $this->resolveVariants($lines);
        $currency = $this->getCurrencyModel((string) ($salesOrder->currencyName ?? 'USD'));

        /** @var Order|null $existing */
        $existing = Order::getByCustomFieldTransactionSafe(
            CustomFieldEnum::NET_SUITE_SALES_ORDER_ID->value,
            (string) $salesOrder->internalId,
            $this->company
        );
        $people = $existing === null
            ? $this->customerAction->execute((string) $salesOrder->entity->internalId)
                ->peoples()
                ->firstOrFail()
            : null;

        // One transaction, so a line mismatch rolls the order back instead of leaving it half-rewritten.
        $order = DB::connection('commerce')->transaction(function () use (
            $existing,
            $people,
            $salesOrder,
            $lines,
            $variants,
            $currency
        ): Order {
            if ($existing === null) {
                $order = $this->createOrder(
                    $salesOrder,
                    $people,
                    $lines,
                    $variants,
                    $currency
                );
            } else {
                $order = $existing;
                $this->updateOrderItems($order, $lines);
            }

            $this->assertLinesMatch($order, $lines, (string) $salesOrder->tranId);

            return $order;
        });

        $order->set(CustomFieldEnum::NET_SUITE_SALES_ORDER_ID->value, (string) $salesOrder->internalId);
        $order->set(CustomFieldEnum::NET_SUITE_SALES_ORDER_NUMBER->value, (string) $salesOrder->tranId);
        $order->set(CustomFieldEnum::NET_SUITE_PO_NUMBER->value, Str::trimToNull($salesOrder->otherRefNum));
        $order->set(CustomFieldEnum::NET_SUITE_LAST_SYNC->value, now()->toIso8601String());

        return $order;
    }

    protected function netSuiteLineSource(): string
    {
        return 'netsuite_sales_order';
    }

    /**
     * @return list<object>
     */
    protected function productLines(SalesOrder $salesOrder): array
    {
        return array_values(array_filter(
            Arr::wrap($salesOrder->itemList->item ?? null),
            fn ($line): bool => Str::trimToNull($line->item->name ?? null) !== null
        ));
    }

    /**
     * Resolves every line before anything is written, so a product NetSuite cannot give us stops the
     * import with nothing half-created on the order side. A variant left live under a deleted product
     * gets its product restored, since Order::addItem reads the product's name.
     *
     * @param list<object> $lines
     * @return array<string, Variants>
     */
    protected function resolveVariants(array $lines): array
    {
        $variants = [];
        $missing = [];

        foreach ($lines as $line) {
            $barcode = (string) $line->item->name;

            if (isset($variants[$barcode])) {
                continue;
            }

            $existing = $this->findVariantBySku($barcode);

            if ($existing?->product !== null) {
                $variants[$barcode] = $existing;

                continue;
            }

            try {
                $variants[$barcode] = $existing === null
                    ? $this->productAction->execute($barcode)
                    : $this->productAction->restore($existing, $barcode);
            } catch (Throwable $e) {
                $missing[$barcode] = $e->getMessage();

                continue;
            }

            if ($existing === null) {
                $this->pulledProducts[] = $barcode;
            } else {
                $this->restoredProducts[] = $barcode;
            }
        }

        if ($missing !== []) {
            throw new ValidationException(
                'Could not add these products from NetSuite, so no order was created: '
                . implode('; ', array_map(
                    fn (string $barcode, string $reason): string => $barcode . ' (' . $reason . ')',
                    array_keys($missing),
                    $missing
                ))
            );
        }

        return $variants;
    }

    /**
     * @param list<object> $lines
     * @param array<string, Variants> $variants
     */
    protected function createOrder(
        SalesOrder $salesOrder,
        People $people,
        array $lines,
        array $variants,
        Currencies $currency
    ): Order {
        $items = array_map(
            fn (object $line): OrderItemData => new OrderItemData(
                app: $this->app,
                variant: $variants[(string) $line->item->name],
                name: Str::trimToNull($line->description ?? null) ?? $variants[(string) $line->item->name]->name,
                sku: (string) $line->item->name,
                quantity: (float) ($line->quantity ?? 0),
                price: (float) ($line->rate ?? 0),
                tax: 0,
                discount: 0,
                currency: $currency,
                metadata: ['source' => $this->netSuiteLineSource()],
            ),
            $lines
        );

        return new CreateOrderAction(
            new OrderData(
                app: $this->app,
                region: RegionRepository::getDefault($this->company),
                company: $this->company,
                people: $people,
                user: $this->user,
                token: IlluminateStr::random(32),
                orderNumber: '',
                shippingAddress: null,
                billingAddress: null,
                total: (float) ($salesOrder->subTotal ?? 0),
                taxes: (float) ($salesOrder->taxTotal ?? 0),
                totalDiscount: abs((float) ($salesOrder->discountTotal ?? 0)),
                totalShipping: (float) ($salesOrder->shippingCost ?? 0),
                status: $this->orderStatus((string) $salesOrder->status),
                checkoutToken: '',
                currency: $currency,
                items: new DataCollection(OrderItemData::class, $items),
                metadata: [
                    'source' => $this->netSuiteLineSource(),
                    'netsuite_total' => (float) ($salesOrder->total ?? 0),
                ],
                customerNote: Str::trimToNull($salesOrder->memo),
                fulfillmentStatus: $this->fulfillmentStatus((string) $salesOrder->status),
                reference: (string) $salesOrder->tranId,
                paymentStatus: 'unpaid',
            )
        )->disableWorkflow()->disableNotifications()->execute();
    }

    /**
     * A line skipped or collapsed on the Kanvas side (an unresolved variant, two NetSuite lines with the
     * same item) shows up as a different line total; NetSuite's own line amounts are the reference.
     *
     * @param list<object> $lines
     */
    protected function assertLinesMatch(Order $order, array $lines, string $tranId): void
    {
        $expected = array_sum(array_map(
            fn (object $line): float => (float) ($line->amount ?? ((float) ($line->quantity ?? 0) * (float) ($line->rate ?? 0))),
            $lines
        ));
        $actual = (float) $order->items()
            ->selectRaw('sum(unit_price_net_amount * quantity) as total')
            ->value('total');

        if (abs($expected - $actual) > 0.01) {
            throw new ValidationException(sprintf(
                'Kanvas order %s does not match NetSuite sales order %s: lines total %.2f in Kanvas vs %.2f in NetSuite',
                $order->getId(),
                $tranId,
                $actual,
                $expected
            ));
        }
    }

    protected function orderStatus(string $netsuiteStatus): string
    {
        return match ($netsuiteStatus) {
            'Cancelled' => OrderStatusEnum::CANCELED->value,
            'Billed', 'Closed' => OrderStatusEnum::COMPLETED->value,
            default => OrderStatusEnum::PENDING->value,
        };
    }

    protected function fulfillmentStatus(string $netsuiteStatus): string
    {
        return match ($netsuiteStatus) {
            'Cancelled' => OrderFulfillmentStatusEnum::CANCELLED->value,
            'Pending Billing', 'Billed', 'Closed' => OrderFulfillmentStatusEnum::COMPLETED->value,
            default => OrderFulfillmentStatusEnum::PENDING->value,
        };
    }
}
