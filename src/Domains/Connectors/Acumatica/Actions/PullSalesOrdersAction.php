<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportOrder;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportOrderItem;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportProduct;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Kanvas\Souk\Orders\Actions\CreateOrderAction as SoukCreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderDto;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemDto;
use Kanvas\Souk\Orders\Models\Order as OrderModel;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

class PullSalesOrdersAction
{
    /** @var array<string, int> per-run skip breakdown, populated by processRows() */
    public array $skipped = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected KanvasRegions $region,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
        protected ?Carbon $modifiedSince = null,
        protected ?string $orderNumber = null,
    ) {
    }

    public function execute(): int
    {
        $headers = $this->fetchHeaders();

        // Fetch lines only for the orders we're actually processing — the SOLine table
        // has millions of rows, so never scan it whole.
        $orderNbrs = array_values(array_unique(array_filter(array_map(
            static fn (array $h): string => trim((string) ($h['OrderNbr'] ?? '')),
            $headers
        ))));

        return $this->processRows($headers, $this->fetchLinesByOrder($orderNbrs));
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchHeaders(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('SOOrder as o')
            ->leftJoin('BAccount as b', function (JoinClause $join): void {
                $join->on('b.BAccountID', '=', 'o.CustomerID')
                    ->on('b.CompanyID', '=', 'o.CompanyID');
            })
            ->where('o.CompanyID', $this->acumaticaCompanyId)
            ->select([
                'o.OrderType', 'o.OrderNbr', 'b.AcctCD', 'o.NoteID',
                'o.Cancelled', 'o.Completed', 'o.Hold',
                'o.OrderTotal', 'o.TaxTotal', 'o.DiscTot', 'o.FreightTot', 'o.CuryID',
                'o.OrderDate', 'o.ShipDate', 'o.OrderDesc', 'o.CustomerOrderNbr', 'o.UsrRMANbr',
            ])
            ->orderByDesc('o.OrderDate');

        if ($this->orderNumber !== null) {
            $query->where('o.OrderNbr', $this->orderNumber);
        }

        if ($this->modifiedSince !== null) {
            $query->where('o.LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, string> $orderNbrs
     *
     * @return array<string, array<int, array<array-key, mixed>>> keyed by "OrderType-OrderNbr"
     */
    protected function fetchLinesByOrder(array $orderNbrs): array
    {
        if ($orderNbrs === []) {
            return [];
        }

        $rows = SqlClient::connection($this->app)
            ->table('SOLine as l')
            ->whereIn('l.OrderNbr', $orderNbrs)
            ->join('InventoryItem as i', function (JoinClause $join): void {
                $join->on('i.InventoryID', '=', 'l.InventoryID')
                    ->on('i.CompanyID', '=', 'l.CompanyID');
            })
            ->leftJoin('INSite as w', function (JoinClause $join): void {
                $join->on('w.SiteID', '=', 'l.SiteID')
                    ->on('w.CompanyID', '=', 'l.CompanyID');
            })
            ->where('l.CompanyID', $this->acumaticaCompanyId)
            ->select([
                'l.OrderType', 'l.OrderNbr', 'i.InventoryCD as sku', 'l.TranDesc',
                'l.OrderQty', 'l.ShippedQty', 'l.UnitPrice', 'l.DiscAmt', 'w.SiteCD as warehouse',
            ])
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = trim((string) $row->OrderType) . '-' . trim((string) $row->OrderNbr);
            $grouped[$key][] = (array) $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<array-key, mixed>>          $headers
     * @param array<string, array<int, array<array-key, mixed>>> $linesByOrder
     */
    public function processRows(array $headers, array $linesByOrder): int
    {
        $count = 0;
        $this->skipped = [
            'no_customer_code' => 0,
            'already_exists' => 0,
            'customer_not_in_kanvas' => 0,
            'no_matching_variants' => 0,
        ];

        foreach ($headers as $header) {
            $key = trim((string) ($header['OrderType'] ?? '')) . '-' . trim((string) ($header['OrderNbr'] ?? ''));
            $order = AcumaticaImportOrder::from($header, $linesByOrder[$key] ?? []);

            if ($order->orderNumber === '' || $order->customerCode === '') {
                $this->skipped['no_customer_code']++;

                continue;
            }

            $exists = OrderModel::query()
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->where('order_number', $order->orderNumber)
                ->first();

            if ($exists !== null) {
                $this->skipped['already_exists']++;

                continue;
            }

            $people = $this->ensurePeople($order->customerCode);

            if ($people === null) {
                $this->skipped['customer_not_in_kanvas']++; // customer code not found in Acumatica

                continue;
            }

            $currency = Currencies::getByCode($order->currency);
            $items = $this->buildItems($order->items, $currency);

            if ($items->count() === 0) {
                $this->skipped['no_matching_variants']++; // line SKUs not synced as products

                continue;
            }

            $createOrder = new SoukCreateOrderAction(new OrderDto(
                app: $this->app,
                region: $this->region,
                company: $this->company,
                people: $people,
                user: $this->user,
                token: $order->token,
                orderNumber: $order->orderNumber,
                shippingAddress: null,
                billingAddress: null,
                total: $order->total,
                taxes: $order->taxes,
                totalDiscount: $order->totalDiscount,
                totalShipping: $order->totalShipping,
                status: $order->status,
                checkoutToken: $order->token,
                currency: $currency,
                items: $items,
                metadata: $order->metadata,
                fulfillmentStatus: $order->fulfillmentStatus,
                reference: $order->reference,
                customerNote: $order->customerNote,
            ));
            $createOrder->runWorkflow = false;
            $orderModel = $createOrder->execute();

            $orderModel->set(
                CustomFieldEnum::ORDER_ID->value,
                (string) $order->customFields[CustomFieldEnum::ORDER_ID->value]
            );

            $count++;
        }

        return $count;
    }

    /**
     * @param DataCollection<int, AcumaticaImportOrderItem> $items
     */
    private function buildItems(DataCollection $items, Currencies $currency): DataCollection
    {
        $orderItems = [];

        foreach ($items as $item) {
            $variant = $this->ensureVariant($item->sku);

            if ($variant === null) {
                continue;
            }

            $orderItems[] = new OrderItemDto(
                app: $this->app,
                variant: $variant,
                name: $item->name,
                sku: $item->sku,
                quantity: $item->quantity,
                price: $item->price,
                tax: $item->tax,
                discount: $item->discount,
                currency: $currency,
                quantityShipped: $item->quantityShipped,
            );
        }

        return new DataCollection(OrderItemDto::class, $orderItems);
    }

    private function ensurePeople(string $acctCd): ?People
    {
        /** @var People|null $people */
        $people = People::getByCustomField(CustomFieldEnum::CUSTOMER_ID->value, $acctCd, $this->company);

        if ($people !== null) {
            return $people;
        }

        $row = $this->fetchCustomerRow($acctCd);

        if ($row === null) {
            return null;
        }

        return new CreateAcumaticaPersonAction(
            $this->app,
            $this->company,
            $this->user
        )->execute($row);
    }

    private function ensureVariant(string $sku): ?Variants
    {
        /** @var Variants|null $variant */
        $variant = Variants::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('sku', $sku)
            ->notDeleted()
            ->first();

        if ($variant !== null) {
            return $variant;
        }

        $row = $this->fetchItemRow($sku);

        if ($row === null) {
            return null;
        }

        new ProductImporterAction(
            AcumaticaImportProduct::from($row),
            $this->company,
            $this->user,
            $this->region,
            $this->app,
            false,
        )->execute();

        /** @var Variants|null $variant */
        $variant = Variants::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('sku', $sku)
            ->notDeleted()
            ->first();

        return $variant;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function fetchCustomerRow(string $acctCd): ?array
    {
        $row = SqlClient::connection($this->app)
            ->table('Customer as p')
            ->join('BAccount as b', function (JoinClause $join): void {
                $join->on('b.BAccountID', '=', 'p.BAccountID')
                    ->on('b.CompanyID', '=', 'p.CompanyID');
            })
            ->leftJoin('Contact as c', function (JoinClause $join): void {
                $join->on('c.ContactID', '=', 'b.DefContactID')
                    ->on('c.CompanyID', '=', 'b.CompanyID');
            })
            ->leftJoin('Address as a', function (JoinClause $join): void {
                $join->on('a.AddressID', '=', 'b.DefAddressID')
                    ->on('a.CompanyID', '=', 'b.CompanyID');
            })
            ->where('p.CompanyID', $this->acumaticaCompanyId)
            ->where('b.AcctCD', $acctCd)
            ->select([
                'b.AcctCD', 'b.AcctName', 'b.NoteID',
                'c.FirstName', 'c.LastName', 'c.EMail', 'c.Phone1',
                'a.AddressLine1', 'a.AddressLine2', 'a.City', 'a.State',
                'a.CountryID', 'a.PostalCode', 'a.Latitude', 'a.Longitude',
            ])
            ->first();

        return $row !== null ? (array) $row : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function fetchItemRow(string $sku): ?array
    {
        $row = SqlClient::connection($this->app)
            ->table('InventoryItem')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->where('InventoryCD', $sku)
            ->first();

        return $row !== null ? (array) $row : null;
    }
}
