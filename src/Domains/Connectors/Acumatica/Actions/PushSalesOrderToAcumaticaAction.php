<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class PushSalesOrderToAcumaticaAction
{
    private ?AcumaticaWriteService $writer;

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

        return $header;
    }

    /**
     * OData filter to adopt a SalesOrder a prior partially-failed push may have already created —
     * keyed on the Kanvas order number we stamp as CustomerOrderNbr — so a retry doesn't duplicate it.
     *
     * @return array<string, mixed>
     */
    private function existingOrderQuery(): array
    {
        $ref = str_replace("'", "''", (string) $this->order->order_number);

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

    private function writer(): AcumaticaWriteService
    {
        return $this->writer ??= new AcumaticaWriteService($this->app);
    }
}
