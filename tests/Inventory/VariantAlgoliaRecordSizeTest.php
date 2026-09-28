<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Kanvas\Inventory\Variants\Models\Variants;
use ReflectionMethod;
use Tests\TestCase;

class VariantAlgoliaRecordSizeTest extends TestCase
{
    private function trim(array $variant): array
    {
        $method = new ReflectionMethod(Variants::class, 'fitWithinAlgoliaRecordLimit');

        return $method->invoke(new Variants(), $variant);
    }

    private function oversizedVariant(): array
    {
        return [
            'objectID' => 'variant-uuid',
            'id' => '437317',
            'products_id' => 1,
            'name' => 'Big Variant',
            'files' => array_fill(0, 5, [
                'uuid' => str_repeat('f', 36),
                'name' => str_repeat('n', 40),
                'url' => 'https://cdn.example.com/' . str_repeat('x', 120),
                'size' => 12345,
                'field_name' => 'photo',
                'attributes' => str_repeat('a', 200),
            ]),
            'company' => ['id' => 1, 'name' => 'ACME'],
            'uuid' => 'variant-uuid',
            'slug' => 'big-variant',
            'sku' => 'SKU-437317',
            'warehouses' => array_fill(0, 30, [
                'id' => 1,
                'name' => 'Warehouse ' . str_repeat('w', 80),
                'price' => 9.99,
                'quantity' => 10,
                'status' => ['id' => 1, 'name' => 'available'],
            ]),
            'channels' => [['id' => 1, 'name' => 'web', 'price' => 9.99, 'is_published' => 1]],
            'attributes' => array_fill(0, 50, str_repeat('attr-value ', 8)),
        ];
    }

    public function testTrimmedRecordFitsUnderAlgoliaLimit(): void
    {
        $original = $this->oversizedVariant();
        $this->assertGreaterThan(10000, strlen((string) json_encode($original)), 'Fixture must start oversized.');

        $trimmed = $this->trim($original);

        $this->assertLessThanOrEqual(10000, strlen((string) json_encode($trimmed)));
    }

    public function testWarehousesStrippedFirstAndCoreFieldsSurvive(): void
    {
        $trimmed = $this->trim($this->oversizedVariant());

        $this->assertSame([], $trimmed['warehouses'], 'Internal stock detail is dropped first under pressure.');
        $this->assertSame('Big Variant', $trimmed['name'], 'Core variant fields must survive.');
        $this->assertSame('SKU-437317', $trimmed['sku']);
        $this->assertNotEmpty($trimmed['channels'], 'Variant pricing must survive.');
    }

    public function testImagesSurviveWhenStrippingWarehousesIsEnough(): void
    {
        $variant = $this->oversizedVariant();
        $variant['attributes'] = [];
        // Heavy warehouse block so the record is over budget ONLY because of it;
        // stripping it alone brings the record under, leaving images intact.
        $variant['warehouses'] = array_fill(0, 80, [
            'id' => 1,
            'name' => 'Warehouse ' . str_repeat('w', 100),
            'price' => 9.99,
            'quantity' => 10,
            'status' => ['id' => 1, 'name' => 'available'],
        ]);
        $this->assertGreaterThan(10000, strlen((string) json_encode($variant)));

        $trimmed = $this->trim($variant);

        $this->assertLessThanOrEqual(10000, strlen((string) json_encode($trimmed)));
        $this->assertSame([], $trimmed['warehouses']);
        $this->assertNotEmpty($trimmed['files'], 'Images survive when stripping warehouses suffices.');
    }

    public function testRecordSizeLimitIsConfigurable(): void
    {
        $original = $this->oversizedVariant();

        config(['scout.algolia.record_size_limit' => 500000]);
        $this->assertSame($original, $this->trim($original), 'A raised budget must leave the record untouched.');

        config(['scout.algolia.record_size_limit' => 9500]);
        $this->assertNotSame($original, $this->trim($original));
    }

    public function testSmallRecordIsLeftUntouched(): void
    {
        $small = [
            'objectID' => 'v1',
            'name' => 'Tiny',
            'sku' => 'S1',
            'files' => [['uuid' => 'img']],
            'warehouses' => [['id' => 1]],
            'channels' => [],
            'attributes' => ['color' => 'red'],
        ];

        $trimmed = $this->trim($small);

        $this->assertSame($small, $trimmed, 'Records that already fit are untouched.');
    }
}
