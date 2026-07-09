<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportOrder;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Tests\TestCase;

class AcumaticaImportOrderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function header(array $overrides = []): array
    {
        return array_merge([
            'OrderType' => 'SO',
            'OrderNbr' => 'SO-1001',
            'AcctCD' => 'ACME-RETAIL',
            'NoteID' => 'ORDER-GUID-0001',
            'Cancelled' => false,
            'Completed' => false,
            'Hold' => false,
            'OrderTotal' => 559.98,
            'TaxTotal' => 40.00,
            'DiscTot' => 10.00,
            'FreightTot' => 15.00,
            'CuryID' => 'USD',
            'OrderDate' => '2026-03-17 00:00:00',
            'OrderDesc' => 'Sample units',
            'CustomerOrderNbr' => 'PO-100',
            'UsrRMANbr' => null,
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        return [
            ['sku' => 'COOLER-X-BLACK', 'TranDesc' => 'Cooler X Black', 'OrderQty' => 1, 'ShippedQty' => 1, 'UnitPrice' => 279.99, 'DiscAmt' => 5.00, 'warehouse' => 'MAIN'],
            ['sku' => 'CASE-H7-WHITE', 'TranDesc' => 'Case H7 White', 'OrderQty' => 1, 'ShippedQty' => 0, 'UnitPrice' => 279.99, 'DiscAmt' => 5.00, 'warehouse' => 'MAIN'],
        ];
    }

    public function testMapsHeaderAndItems(): void
    {
        $order = AcumaticaImportOrder::fromRow($this->header(), $this->lines());

        $this->assertSame('SO-1001', $order['orderNumber']);
        $this->assertSame('SO', $order['orderType']);
        $this->assertSame('ACME-RETAIL', $order['customerCode']);
        $this->assertSame('USD', $order['currency']);
        $this->assertSame(559.98, $order['total']);
        $this->assertSame(40.00, $order['taxes']);
        $this->assertSame('SO-SO-1001', $order['customFields'][CustomFieldEnum::ORDER_ID->value]);

        $this->assertCount(2, $order['items']);
        $this->assertSame('COOLER-X-BLACK', $order['items'][0]['sku']);
        $this->assertSame(279.99, $order['items'][0]['price']);
        $this->assertSame(1, $order['items'][0]['quantityShipped']);
    }

    public function testStatusMapping(): void
    {
        $this->assertSame(
            OrderStatusEnum::PENDING->value,
            AcumaticaImportOrder::fromRow($this->header(), $this->lines())['status']
        );
        $this->assertSame(
            OrderStatusEnum::COMPLETED->value,
            AcumaticaImportOrder::fromRow($this->header(['Completed' => true]), $this->lines())['status']
        );
        $this->assertSame(
            OrderStatusEnum::CANCELED->value,
            AcumaticaImportOrder::fromRow($this->header(['Cancelled' => true]), $this->lines())['status']
        );
    }

    public function testFulfillmentIsPendingWhenNotAllShipped(): void
    {
        $order = AcumaticaImportOrder::fromRow($this->header(), $this->lines());
        $this->assertSame(OrderFulfillmentStatusEnum::PENDING->value, $order['fulfillmentStatus']);
    }

    public function testFulfillmentIsCompletedWhenAllShipped(): void
    {
        $lines = [
            ['sku' => 'COOLER-X-BLACK', 'OrderQty' => 2, 'ShippedQty' => 2, 'UnitPrice' => 279.99],
        ];
        $order = AcumaticaImportOrder::fromRow($this->header(), $lines);
        $this->assertSame(OrderFulfillmentStatusEnum::COMPLETED->value, $order['fulfillmentStatus']);
    }
}
