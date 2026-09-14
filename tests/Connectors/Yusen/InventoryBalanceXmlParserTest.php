<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Kanvas\Connectors\Yusen\Services\InventoryBalanceXmlParser;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class InventoryBalanceXmlParserTest extends TestCase
{
    private function fixturePath(): string
    {
        return __DIR__ . '/fixtures/item-balance.xml';
    }

    public function testParsesUploadHeader(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        $this->assertSame('11111111-2222-3333-4444-555555555555', $balance->externalId);
        $this->assertSame(1, $balance->groupIndex);
        $this->assertSame(1, $balance->numGroups);
        $this->assertSame(5, $balance->declaredRecords);
        $this->assertSame(5, $balance->totalRecords);
        $this->assertSame('2026-08-21', $balance->generatedAt?->format('Y-m-d'));
    }

    public function testAggregatesLotRecordsPerItemAndWarehouse(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        $line = $balance->lines['9990000000045|WHSE1'];

        $this->assertSame(1000.0, $line->quantity);
        $this->assertSame(2, $line->recordCount);
        $this->assertSame(750.0, $line->allocatedQuantity);
        $this->assertSame(3.0, $line->suspenseQuantity);
        $this->assertSame(1, $balance->multiRecordItems());
    }

    public function testKeepsQuantityBrokenDownByStatus(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        $this->assertSame(
            ['Available' => 988.0, 'Damaged' => 12.0],
            $balance->lines['9990000000045|WHSE1']->statusBreakdown
        );
    }

    public function testSeparatesTheSameCatalogAcrossWarehouses(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        $this->assertSame(['WHSE1', 'WHSE2'], $balance->warehouseCodes);
        $this->assertArrayHasKey('9990000078419|WHSE2', $balance->lines);
        $this->assertSame(1900.0, $balance->lines['9990000078419|WHSE2']->quantity);
    }

    public function testIgnoresSerialNumberSubtrees(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        // The serialised item carries two <SerialNumber> children, each with its own
        // <ObjectId>. They must not be mistaken for inventory records.
        $line = $balance->lines['9990000065686|WHSE1'];

        $this->assertSame(26.0, $line->quantity);
        $this->assertSame(1, $line->recordCount);
    }

    public function testLeavesOptionalSkuFieldsNullWhenAbsent(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        $this->assertNull($balance->lines['9990000065686|WHSE1']->size);
        $this->assertSame('Individual', $balance->lines['9990000000014|WHSE1']->size);
    }

    public function testSkipsRepeatedInternalIds(): void
    {
        $xml = (string) file_get_contents($this->fixturePath());

        // Manhattan re-sends a record when an ack times out; the same InternalID must not be
        // counted into the item twice.
        $duplicated = str_replace(
            '</Inventories>',
            $this->firstInventoryElement($xml) . '</Inventories>',
            $xml
        );

        $balance = new InventoryBalanceXmlParser()->parseString($duplicated);

        $this->assertSame(5, $balance->totalRecords);
        $this->assertSame(110.0, $balance->lines['9990000000014|WHSE1']->quantity);
    }

    public function testTotalsAcrossEveryLine(): void
    {
        $balance = new InventoryBalanceXmlParser()->parseFile($this->fixturePath());

        $this->assertSame(4, count($balance->lines));
        $this->assertSame(3036.0, $balance->totalQuantity());
    }

    public function testRejectsAnEmptyPayload(): void
    {
        $this->expectException(ValidationException::class);

        new InventoryBalanceXmlParser()->parseString('   ');
    }

    public function testRejectsAnUnreadableFile(): void
    {
        $this->expectException(ValidationException::class);

        new InventoryBalanceXmlParser()->parseFile('/tmp/does-not-exist-' . uniqid() . '.xml');
    }

    private function firstInventoryElement(string $xml): string
    {
        $start = strpos($xml, '<Inventory>');
        $end = strpos($xml, '</Inventory>');

        return substr($xml, (int) $start, (int) $end - (int) $start + strlen('</Inventory>'));
    }
}
