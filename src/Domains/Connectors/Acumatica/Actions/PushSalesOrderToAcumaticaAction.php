<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class PushSalesOrderToAcumaticaAction
{
    use HasAcumaticaWriter;

    public function __construct(
        protected Apps $app,
        protected Order $order,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->writer = $writer;
    }

    public function execute(): string
    {
        $existing = (string) $this->order->get(CustomFieldEnum::ORDER_ID->value, '');

        if ($existing !== '') {
            return $existing;
        }

        $customerCode = $this->ensureCustomerCode();

        if ($customerCode === '') {
            throw new AcumaticaWriteException(
                "Order {$this->order->getId()} has no customer — assign a buyer before pushing."
            );
        }

        $record = $this->writer()->push(
            'SalesOrder',
            $this->buildPayload($customerCode),
            findQuery: $this->existingOrderQuery(),
        );

        $id = AcumaticaPayload::recordId($record);
        $orderNbr = (string) (AcumaticaPayload::value($record, 'OrderNbr') ?? $id ?? '');

        if ($id !== null) {
            $this->order->set(CustomFieldEnum::ORDER_ID->value, $id);
        }

        if ($orderNbr !== '') {
            $this->order->set(CustomFieldEnum::ORDER_REF->value, $orderNbr);
        }

        return $orderNbr;
    }

    /**
     * The exact payload without writing — dry-run / debugging. Read-only: never creates the customer.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $people = $this->order->people;
        $code = $people !== null ? (string) $people->get(CustomFieldEnum::CUSTOMER_ID->value, '') : '';

        return $this->buildPayload($code);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $customerCode): array
    {
        $header = AcumaticaPayload::wrap([
            'OrderType' => (string) ($this->order->get(CustomFieldEnum::ORDER_TYPE->value) ?? 'SO'),
            'CustomerID' => $customerCode,
            'CustomerOrderNbr' => (string) $this->order->order_number,
            'OrderDesc' => $this->order->customer_note,
        ]);

        $header['Details'] = $this->buildLines();

        $custom = $this->buildCustomFields();

        if ($custom !== []) {
            $header['custom'] = $custom;
        }

        return $header;
    }

    /**
     * Build the `custom` node from the tenant's ACUMATICA_SO_CUSTOM_FIELDS config. Contract-based
     * REST hangs user-defined fields off a named data view (the SO header view is `Document`), each
     * as `{type, value}` — so the shape is `custom.Document.UsrX = {type, value}`. Date specs resolve
     * to order date + N days; this is how a tenant's required order-date customizations get satisfied
     * without the connector knowing their field names.
     *
     * @return array<string, array<string, array{type: string, value: string}>>
     */
    private function buildCustomFields(): array
    {
        $config = $this->app->get(ConfigurationEnum::SO_CUSTOM_FIELDS->value);

        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        if (! is_array($config) || $config === []) {
            return [];
        }

        $baseDate = $this->order->created_at ?? Carbon::now();
        $custom = [];

        foreach ($config as $field => $spec) {
            if (! is_array($spec)) {
                $spec = is_numeric($spec) ? ['days' => (int) $spec] : ['value' => (string) $spec];
            }

            $view = (string) ($spec['view'] ?? 'Document');

            if (isset($spec['days'])) {
                $type = (string) ($spec['type'] ?? 'DateTime');
                $value = $baseDate->copy()->addDays((int) $spec['days'])->format('Y-m-d\T00:00:00');
            } else {
                $type = (string) ($spec['type'] ?? 'String');
                $value = (string) ($spec['value'] ?? '');
            }

            $custom[$view][(string) $field] = ['type' => $type, 'value' => $value];
        }

        return $custom;
    }

    /**
     * OData filter to adopt a SalesOrder a prior partially-failed push may have already created —
     * keyed on the Kanvas order number we stamp as CustomerOrderNbr — so a retry doesn't duplicate it.
     *
     * @return array<string, mixed>
     */
    private function existingOrderQuery(): array
    {
        $ref = AcumaticaPayload::escapeLiteral((string) $this->order->order_number);

        return ['$filter' => "CustomerOrderNbr eq '{$ref}'", '$top' => 1];
    }

    /**
     * @return array<int, array<string, array{value: mixed}>>
     */
    private function buildLines(): array
    {
        $lines = [];

        foreach ($this->order->items as $item) {
            $lines[] = AcumaticaPayload::wrap([
                'InventoryID' => $item->product_sku,
                'OrderQty' => (float) $item->quantity,
                'UnitPrice' => (float) $item->unit_price_gross_amount,
            ]);
        }

        return $lines;
    }

    private function ensureCustomerCode(): string
    {
        /** @var People|null $people */
        $people = $this->order->people;

        if ($people === null) {
            return '';
        }

        return new EnsureAcumaticaCustomerAction(
            $this->app,
            $people,
            email: $this->order->user_email,
            writer: $this->writer(),
        )->execute();
    }
}
