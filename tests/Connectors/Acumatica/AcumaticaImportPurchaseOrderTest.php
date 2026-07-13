<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportPurchaseOrder;
use Tests\TestCase;

class AcumaticaImportPurchaseOrderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function header(array $overrides = []): array
    {
        return array_merge([
            'OrderType' => 'RO',
            'OrderNbr' => 'PO1001',
            'AcctCD' => 'V0000505',
            'Status' => 'N',
            'OrderDate' => '2026-07-01 00:00:00',
            'CuryID' => 'USD',
            'CuryOrderTotal' => 97812.00,
            'OrderTotal' => 97812.00,
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        return [
            ['LineNbr' => 1, 'sku' => 'RL-KP336-RB   ', 'TranDesc' => 'Kraken 360 Black', 'AccountCD' => null, 'SubCD' => null,
                'OrderQty' => 756, 'OpenQty' => 756, 'ReceivedQty' => 0, 'CuryUnitCost' => 66, 'CuryExtCost' => 49896],
            ['LineNbr' => 2, 'sku' => 'SVC-FREIGHT', 'TranDesc' => 'Freight', 'AccountCD' => '6300', 'SubCD' => 'LOG-CA-000',
                'OrderQty' => 1, 'OpenQty' => 1, 'ReceivedQty' => 0, 'CuryUnitCost' => 500, 'CuryExtCost' => 500],
        ];
    }

    public function testMapsHeader(): void
    {
        $po = AcumaticaImportPurchaseOrder::from($this->header(), $this->lines());

        $this->assertSame('RO-PO1001', $po->externalId);
        $this->assertSame('RO', $po->orderType);
        $this->assertSame('PO1001', $po->orderNumber);
        $this->assertSame('V0000505', $po->vendorCode);
        $this->assertSame('N', $po->status);
        $this->assertSame('USD', $po->currency);
        $this->assertSame(97812.00, $po->orderTotal);
        $this->assertSame('2026-07-01', $po->orderDate?->toDateString());
        $this->assertCount(2, $po->lines);
    }

    public function testMapsLinesIncludingCodingAndSkuTrim(): void
    {
        $po = AcumaticaImportPurchaseOrder::from($this->header(), $this->lines());

        $inventoryLine = $po->lines[0];
        $this->assertSame('RL-KP336-RB', $inventoryLine->sku, 'SKU is trimmed.');
        $this->assertNull($inventoryLine->accountCd, 'Inventory PO line has no expense account.');
        $this->assertNull($inventoryLine->subCode);
        $this->assertSame(756.0, $inventoryLine->openQty);
        $this->assertSame(66.0, $inventoryLine->unitCost);

        $serviceLine = $po->lines[1];
        $this->assertSame('6300', $serviceLine->accountCd, 'Service line carries the expense account.');
        $this->assertSame('LOG-CA-000', $serviceLine->subCode, 'And its subaccount.');
    }

    public function testCurrencyFallsBackToUsd(): void
    {
        $po = AcumaticaImportPurchaseOrder::from($this->header(['CuryID' => '']), []);
        $this->assertSame('USD', $po->currency);
        $this->assertCount(0, $po->lines);
    }
}
