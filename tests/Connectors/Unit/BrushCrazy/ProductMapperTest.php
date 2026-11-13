<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\ProductMapper;
use Tests\TestCase;

final class ProductMapperTest extends TestCase
{
    public function testStudioPriceOverridesTheMsrp(): void
    {
        $this->assertSame(19.99, ProductMapper::map($this->product())['price']);
        $this->assertSame(24.5, ProductMapper::map($this->product(), (object) ['price' => '{"amount":2450,"currency":"USD"}'])['price']);
        // A setting row without its own price falls back to the global one.
        $this->assertSame(19.99, ProductMapper::map($this->product(), (object) ['price' => null])['price']);
    }

    public function testBarcodeBecomesTheSkuWhenItFits(): void
    {
        $mapped = ProductMapper::map($this->product());

        $this->assertSame('0123456789', $mapped['variant']['sku']);
        $this->assertSame('0123456789', $mapped['variant']['barcode']);
        $this->assertSame('0123456789', $mapped['product']['upc']);
    }

    /** `products_variants.sku` is 64 wide, BrushCrazy barcodes 191. */
    public function testOverlongBarcodeFallsBackToTheSourceId(): void
    {
        $mapped = ProductMapper::map($this->product(['barcode' => str_repeat('9', 80)]));

        $this->assertSame('bc-product-42', $mapped['variant']['sku']);
        $this->assertNull($mapped['variant']['barcode']);
    }

    public function testDeletedOrDiscontinuedProductIsNotPublished(): void
    {
        $deleted = ProductMapper::map($this->product(['deleted_at' => '2023-01-01 00:00:00']));
        $discontinued = ProductMapper::map($this->product(['discontinued_at' => '2023-01-01 00:00:00']));

        $this->assertTrue($deleted['product']['is_deleted']);
        $this->assertTrue($deleted['variant']['is_deleted']);
        $this->assertFalse($deleted['product']['is_published']);
        $this->assertFalse($discontinued['product']['is_published']);
        $this->assertTrue(ProductMapper::map($this->product())['product']['is_published']);
    }

    public function testGenericLineIsKeyedByItsKind(): void
    {
        $line = ProductMapper::genericLine(SaleLineKindEnum::CLASS_SEAT);

        $this->assertSame('Class Seat', $line['product']['name']);
        $this->assertSame('bc-sale-line-class-seat', $line['variant']['sku']);
        $this->assertSame('sale-line:class-seat', SaleLineKindEnum::CLASS_SEAT->lookupKey());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 42,
            'barcode' => '0123456789',
            'name' => 'Canvas 11x14',
            'description' => null,
            'msrp' => '{"amount":1999,"currency":"USD"}',
            'cost' => '{"amount":500,"currency":"USD"}',
            'published_at' => '2020-01-01 00:00:00',
            'discontinued_at' => null,
            'deleted_at' => null,
            'created_at' => '2020-01-01 00:00:00',
        ]);
    }
}
