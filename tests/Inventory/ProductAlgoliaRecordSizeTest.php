<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Kanvas\Inventory\Products\Models\Products;
use ReflectionMethod;
use Tests\TestCase;

class ProductAlgoliaRecordSizeTest extends TestCase
{
    private function trim(array $product): array
    {
        $method = new ReflectionMethod(Products::class, 'fitWithinAlgoliaRecordLimit');

        return $method->invoke(new Products(), $product);
    }

    private function oversizedProduct(): array
    {
        $variants = [];
        for ($i = 0; $i < 200; $i++) {
            $variants[] = [
                'id' => (string) $i,
                'sku' => 'SKU-' . $i,
                'name' => 'Variant ' . $i,
                'files' => array_fill(0, 5, [
                    'uuid' => str_repeat('f', 36),
                    'url' => 'https://cdn.example.com/' . str_repeat('x', 80),
                    'attributes' => str_repeat('a', 120),
                ]),
                'warehouses' => array_fill(0, 4, [
                    'id' => $i,
                    'name' => 'Warehouse ' . $i,
                    'price' => 9.99,
                    'quantity' => 10,
                    'status' => ['id' => 1, 'name' => 'available'],
                ]),
                'channels' => [['id' => 1, 'name' => 'web', 'price' => 9.99, 'is_published' => 1]],
            ];
        }

        return [
            'objectID' => 'product-uuid',
            'id' => '236938',
            'name' => 'Big Product',
            'files' => [['uuid' => 'img', 'url' => 'https://cdn.example.com/main.jpg']],
            'attributes' => ['color' => 'red', 'size' => 'L'],
            'categories' => [['id' => 1, 'name' => 'Cat']],
            'description' => str_repeat('long description ', 300),
            'short_description' => str_repeat('short ', 100),
            'translations' => ['name' => str_repeat('n ', 200), 'description' => str_repeat('d ', 500)],
            'custom_fields' => ['blob' => str_repeat('c', 4000)],
            'variants' => $variants,
        ];
    }

    public function testTrimmedRecordFitsUnderAlgoliaLimit(): void
    {
        $original = $this->oversizedProduct();
        $this->assertGreaterThan(10000, strlen((string) json_encode($original)), 'Fixture must start oversized.');

        $trimmed = $this->trim($original);

        $this->assertLessThanOrEqual(10000, strlen((string) json_encode($trimmed)));
    }

    public function testCoreDisplayFieldsArePreserved(): void
    {
        $trimmed = $this->trim($this->oversizedProduct());

        $this->assertSame('Big Product', $trimmed['name']);
        $this->assertNotEmpty($trimmed['files'], 'Product image must survive trimming.');
        $this->assertSame(['color' => 'red', 'size' => 'L'], $trimmed['attributes'], 'Product attributes must survive.');
        $this->assertNotEmpty($trimmed['categories']);
    }

    public function testWarehousesStrippedUnderPressureButCoreVariantFieldsSurvive(): void
    {
        $trimmed = $this->trim($this->oversizedProduct());

        foreach ($trimmed['variants'] as $variant) {
            $this->assertArrayNotHasKey('warehouses', $variant, 'Internal stock detail is dropped under pressure.');
            $this->assertArrayHasKey('sku', $variant, 'Core variant fields must survive.');
            $this->assertArrayHasKey('channels', $variant, 'Variant pricing must survive.');
        }
    }

    public function testVariantImagesSurviveWhenRecordFitsAfterLighterTrims(): void
    {
        // A record that's over budget only because of custom_fields/translations
        // must keep variant images — they're sacrificed last.
        $product = [
            'objectID' => 'p',
            'name' => 'Has variant images',
            'files' => [['uuid' => 'img']],
            'custom_fields' => ['blob' => str_repeat('c', 12000)],
            'translations' => ['name' => 'x'],
            'variants' => [
                ['id' => '1', 'sku' => 'S1', 'files' => [['uuid' => 'vimg', 'url' => 'https://cdn/x.jpg']], 'channels' => []],
            ],
        ];
        $this->assertGreaterThan(10000, strlen((string) json_encode($product)));

        $trimmed = $this->trim($product);

        $this->assertLessThanOrEqual(10000, strlen((string) json_encode($trimmed)));
        $this->assertArrayHasKey('files', $trimmed['variants'][0], 'Variant images survive when lighter trims suffice.');
        $this->assertNotEmpty($trimmed['variants'][0]['files']);
    }

    public function testLongAttributeCopyIsSacrificedBeforeVariants(): void
    {
        // Mirrors the real Micro Center shape: a base record so heavy with long-form attribute
        // copy that every variant used to get popped to make room.
        $product = [
            'objectID' => 'p',
            'name' => 'Gaming PC',
            'files' => [['uuid' => 'img', 'url' => 'https://cdn/x.jpg']],
            'attributes' => [
                'product_details' => ['title' => 'Product Details', 'quick_spec' => str_repeat('spec ', 1200)],
                'marketing_copy' => ['title' => 'Marketing Copy', 'quick_spec' => str_repeat('copy ', 1200)],
                'gpu' => ['title' => 'GPU', 'quick_spec' => 'RTX 5070'],
            ],
            'variants' => [
                ['id' => '1', 'sku' => 'S1', 'channels' => [['id' => 1, 'price' => 9.99]]],
                ['id' => '2', 'sku' => 'S2', 'channels' => [['id' => 1, 'price' => 19.99]]],
            ],
        ];
        $this->assertGreaterThan(9500, strlen((string) json_encode($product)), 'Fixture must start oversized.');

        $trimmed = $this->trim($product);

        $this->assertCount(2, $trimmed['variants'], 'Variants must survive — attribute copy is trimmed first.');
        $this->assertSame('S1', $trimmed['variants'][0]['sku']);
        $this->assertNotEmpty($trimmed['variants'][0]['channels'], 'Variant pricing must survive.');
        $this->assertSame('RTX 5070', $trimmed['attributes']['gpu']['quick_spec'], 'Short attributes are untouched.');
        $this->assertLessThanOrEqual(9500, strlen((string) json_encode($trimmed)));
    }

    public function testAttributeKeysAreNeverDroppedOnlyTruncated(): void
    {
        // The Algolia facets are `attributes.*.quick_spec`; losing a key breaks the facet UI.
        // Under maximum pressure every key must still be there, just shorter.
        $attributes = [];
        for ($i = 0; $i < 40; $i++) {
            $attributes['spec_' . $i] = ['title' => 'Spec ' . $i, 'quick_spec' => str_repeat('verbose ', 400)];
        }
        $attributes['gpu'] = ['title' => 'GPU', 'quick_spec' => 'RTX 5070'];

        $product = [
            'objectID' => 'p',
            'name' => 'Spec Monster',
            'attributes' => $attributes,
            'variants' => [['id' => '1', 'sku' => 'S1', 'channels' => []]],
        ];

        $trimmed = $this->trim($product);

        $this->assertSame(
            array_keys($attributes),
            array_keys($trimmed['attributes']),
            'Every attribute key must survive — only values get shortened.'
        );
        $this->assertSame('RTX 5070', $trimmed['attributes']['gpu']['quick_spec'], 'Short values are untouched.');
        $this->assertStringEndsWith('...', $trimmed['attributes']['spec_0']['quick_spec'], 'Long values are truncated.');
        $this->assertLessThan(
            strlen($attributes['spec_0']['quick_spec']),
            strlen($trimmed['attributes']['spec_0']['quick_spec'])
        );
    }

    public function testVariantsAreOnlyDroppedWhenNothingElseFits(): void
    {
        $product = $this->oversizedProduct();

        $trimmed = $this->trim($product);

        // 200 fat variants can't all fit, but the record must not come back empty the way
        // the Micro Center index did.
        $this->assertNotEmpty($trimmed['variants'], 'Some variants must always survive.');
        $this->assertLessThanOrEqual(9500, strlen((string) json_encode($trimmed)));
    }

    public function testRecordSizeLimitIsConfigurable(): void
    {
        $original = $this->oversizedProduct();

        config(['scout.algolia.record_size_limit' => 500000]);
        $this->assertSame($original, $this->trim($original), 'A raised budget must leave the record untouched.');

        config(['scout.algolia.record_size_limit' => 9500]);
        $this->assertNotSame($original, $this->trim($original));
    }

    public function testSmallRecordIsLeftUntouched(): void
    {
        $small = [
            'objectID' => 'p1',
            'name' => 'Tiny',
            'files' => [['uuid' => 'img']],
            'translations' => ['name' => 'Tiny'],
            'custom_fields' => ['a' => 'b'],
            'variants' => [
                ['id' => '1', 'sku' => 'S1', 'files' => [['uuid' => 'x']], 'warehouses' => [['id' => 1]], 'channels' => []],
            ],
        ];

        $trimmed = $this->trim($small);

        // Nothing is touched when the record already fits — variant images and
        // warehouses included.
        $this->assertSame($small, $trimmed);
    }
}
