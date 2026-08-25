<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

/**
 * Regression cover for Sentry KANVAS-ECOSYSTEM-628, which took down Order indexing with
 * "Field `items.unit_price_net_amount` must be an array of int64".
 *
 * Typesense types an undeclared child of an object/object[] field from the first document that
 * carries it, and that decision is permanent for the collection. Because json_encode drops the zero
 * fraction, whether a money field lands as int64 or float comes down to whether the first order
 * indexed happened to have a round price — so every nested numeric has to be declared up front.
 */
final class TypesenseNestedNumericFieldTest extends TestCase
{
    public function testAWholeMoneyValueEncodesAsAJsonIntegerWhichIsWhyThisMattersAtAll(): void
    {
        $encoded = json_encode(['unit_price_net_amount' => (float) 10]);

        $this->assertSame('{"unit_price_net_amount":10}', $encoded);
        $this->assertSame('{"unit_price_net_amount":19.99}', json_encode(['unit_price_net_amount' => 19.99]));
    }

    public function testOrderDeclaresEveryItemMoneyAndQuantityFieldAsFloat(): void
    {
        $types = $this->fieldTypes(new Order());

        foreach ([
            'items.unit_price_net_amount',
            'items.unit_price_gross_amount',
            'items.quantity',
            'items.quantity_fulfilled',
            'items.tax_rate',
        ] as $field) {
            $this->assertSame('float[]', $types[$field] ?? null, "{$field} must be declared float[]");
        }
    }

    public function testVariantsDeclaresItsNestedPricesAsFloat(): void
    {
        $types = $this->fieldTypes(new Variants());

        $this->assertSame('float[]', $types['warehouses.price'] ?? null);
        $this->assertSame('float[]', $types['channels.price'] ?? null);
    }

    public function testProductsDeclaresTheVariantPayloadItEmbedsAndItsDynamicB2bPrices(): void
    {
        $types = $this->fieldTypes(new Products());

        $this->assertSame('float[]', $types['variants.warehouses.price'] ?? null);
        $this->assertSame('float[]', $types['variants.channels.price'] ?? null);
        $this->assertSame('float[]', $types['variants.rating'] ?? null);

        // Keys are named after the company id, so only a regex field can type them.
        $this->assertSame('float', $types['prices\\..*'] ?? null);
    }

    /**
     * A declared child is useless if its parent object isn't there — Typesense rejects a nested
     * field whose parent the schema never declares.
     */
    public function testEveryDeclaredNestedFieldHasItsParentObjectDeclared(): void
    {
        $failures = [];

        foreach ([Order::class, Variants::class, Products::class] as $class) {
            $types = $this->fieldTypes(new $class());

            foreach (array_keys($types) as $name) {
                if (! str_contains($name, '.') || str_contains($name, '\\.')) {
                    continue;
                }

                $parent = explode('.', $name)[0];

                if (! isset($types[$parent])) {
                    $failures[] = "{$class}: '{$name}' has no declared parent '{$parent}'";
                }
            }
        }

        $this->assertSame([], $failures, implode(PHP_EOL, $failures));
    }

    /**
     * @return array<string, string>
     */
    private function fieldTypes(Model $model): array
    {
        $model->setRelation('app', app(Apps::class));

        return array_column($model->typesenseCollectionSchema()['fields'], 'type', 'name');
    }
}
