<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Kanvas\Souk\Orders\Models\Order;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An order carries every item plus free-form metadata, so a big enough order blows past the
 * Algolia record cap and takes the whole batch down with it.
 */
class OrderAlgoliaRecordSizeTest extends TestCase
{
    private function trim(array $order): array
    {
        $method = new ReflectionMethod(Order::class, 'fitWithinAlgoliaRecordLimit');

        return $method->invoke(new Order(), $order);
    }

    private function oversizedOrder(): array
    {
        $items = [];
        for ($i = 0; $i < 300; $i++) {
            $items[] = [
                'id' => $i,
                'name' => 'Product ' . $i,
                'sku' => 'SKU-' . $i,
                'quantity' => 2,
                'price' => 19.99,
                'variant' => ['id' => $i, 'name' => 'Variant ' . $i, 'attributes' => str_repeat('x', 80)],
            ];
        }

        return [
            'objectID' => 'order-uuid',
            'id' => '99',
            'order_number_text' => '1024',
            'customer_name' => 'Max Castro',
            'products_text' => str_repeat('Product name SKU-123 ', 400),
            'metadata_text' => str_repeat('metadata blob ', 500),
            'items' => $items,
            'created_at' => 1755314000,
        ];
    }

    public function testOversizedOrderFitsUnderTheLimit(): void
    {
        config(['scout.algolia.record_size_limit' => 9500]);

        $original = $this->oversizedOrder();
        $this->assertGreaterThan(9500, strlen((string) json_encode($original)), 'Fixture must start oversized.');

        $trimmed = $this->trim($original);

        $this->assertLessThanOrEqual(9500, strlen((string) json_encode($trimmed)));
        $this->assertSame('order-uuid', $trimmed['objectID']);
        $this->assertSame('1024', $trimmed['order_number_text'], 'An order must stay findable by number.');
        $this->assertSame('Max Castro', $trimmed['customer_name']);
    }

    public function testRecordSizeLimitIsConfigurable(): void
    {
        $original = $this->oversizedOrder();

        config(['scout.algolia.record_size_limit' => 500000]);
        $this->assertSame($original, $this->trim($original), 'A raised budget must leave the record untouched.');

        config(['scout.algolia.record_size_limit' => 9500]);
        $this->assertNotSame($original, $this->trim($original));
    }
}
