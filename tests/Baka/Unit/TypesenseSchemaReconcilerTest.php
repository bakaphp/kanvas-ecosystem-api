<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Search\TypesenseSchemaReconciler;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Collections;

final class TypesenseSchemaReconcilerTest extends TestCase
{
    public function testReportsAMoneyFieldTheLiveCollectionLockedToInt64(): void
    {
        $reconciler = $this->reconciler($this->liveFields([
            'items' => 'object[]',
            'items.unit_price_net_amount' => 'int64[]',
            'items.quantity' => 'float[]',
        ]));

        $drift = $reconciler->drift($this->model());

        $this->assertCount(1, $drift);
        $this->assertSame('items.unit_price_net_amount', $drift[0]['name']);
        $this->assertSame('int64[]', $drift[0]['from']);
        $this->assertSame('float[]', $drift[0]['to']);
        $this->assertTrue($drift[0]['widening']);
    }

    public function testAnAlignedCollectionHasNoDrift(): void
    {
        $reconciler = $this->reconciler($this->liveFields([
            'items' => 'object[]',
            'items.unit_price_net_amount' => 'float[]',
            'items.quantity' => 'float[]',
        ]));

        $this->assertSame([], $reconciler->drift($this->model()));
    }

    public function testAFieldTheCollectionDoesNotHaveYetIsNotDrift(): void
    {
        $reconciler = $this->reconciler($this->liveFields(['items' => 'object[]']));

        $this->assertSame([], $reconciler->drift($this->model()));
    }

    public function testTheDocumentIdAndDefaultSortingFieldAreNeverTouched(): void
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'default_sorting_field' => 'created_at',
            'fields' => [
                ['name' => 'id', 'type' => 'int64'],
                ['name' => 'created_at', 'type' => 'int64'],
                ['name' => 'items', 'type' => 'object[]'],
            ],
        ]);

        $model = $this->model([
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'created_at', 'type' => 'float'],
            ['name' => 'items', 'type' => 'object[]'],
        ]);

        $this->assertSame([], $this->reconciler($collection)->drift($model));
    }

    public function testReconcileDropsAndReAddsOnlyTheDriftedField(): void
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'fields' => [
                ['name' => 'items', 'type' => 'object[]'],
                ['name' => 'items.unit_price_net_amount', 'type' => 'int64[]'],
                ['name' => 'items.quantity', 'type' => 'float[]'],
            ],
        ]);

        $collection->shouldReceive('update')
            ->once()
            ->with([
                'fields' => [
                    ['name' => 'items.unit_price_net_amount', 'drop' => true],
                    ['name' => 'items.unit_price_net_amount', 'type' => 'float[]', 'optional' => true],
                ],
            ])
            ->andReturn([]);

        $result = $this->reconciler($collection)->reconcile($this->model());

        $this->assertSame([], $result['failed']);
        $this->assertCount(1, $result['altered']);
        $this->assertSame('items.unit_price_net_amount', $result['altered'][0]['name']);
    }

    /**
     * Typesense answers "There are duplicate field names in the schema" and half-applies the alter
     * when one request re-types two nested fields whose names share a prefix — `items.quantity` and
     * `items.quantity_fulfilled` — so each field has to go in its own request.
     */
    public function testEachFieldIsAlteredInItsOwnRequest(): void
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'fields' => [
                ['name' => 'items', 'type' => 'object[]'],
                ['name' => 'items.quantity', 'type' => 'int64[]'],
                ['name' => 'items.quantity_fulfilled', 'type' => 'int64[]'],
            ],
        ]);

        $requests = [];
        $collection->shouldReceive('update')
            ->twice()
            ->andReturnUsing(function (array $payload) use (&$requests) {
                $requests[] = $payload;

                return [];
            });

        $model = $this->model([
            ['name' => 'items', 'type' => 'object[]'],
            ['name' => 'items.quantity', 'type' => 'float[]', 'optional' => true],
            ['name' => 'items.quantity_fulfilled', 'type' => 'float[]', 'optional' => true],
        ]);

        $result = $this->reconciler($collection)->reconcile($model);

        $this->assertCount(2, $result['altered']);
        $this->assertCount(2, $requests);

        foreach ($requests as $payload) {
            $this->assertCount(2, $payload['fields'], 'a request must carry one drop and one add');
            $this->assertSame($payload['fields'][0]['name'], $payload['fields'][1]['name']);
        }
    }

    /**
     * A half-applied alter leaves the field registered twice, under the old type and the new one.
     * Collapsing the live schema to one type per name would report that as aligned.
     */
    public function testAFieldRegisteredTwiceIsReportedAsDrift(): void
    {
        $reconciler = $this->reconciler($this->liveFieldList([
            ['name' => 'items', 'type' => 'object[]'],
            ['name' => 'items.unit_price_net_amount', 'type' => 'int64[]'],
            ['name' => 'items.unit_price_net_amount', 'type' => 'float[]'],
            ['name' => 'items.quantity', 'type' => 'float[]'],
        ]));

        $drift = $reconciler->drift($this->model());

        $this->assertCount(1, $drift);
        $this->assertSame('items.unit_price_net_amount', $drift[0]['name']);
        $this->assertSame('int64[] + float[]', $drift[0]['from']);
        $this->assertTrue($drift[0]['widening']);
    }

    public function testAFailedAlterIsReportedWithoutAbortingTheRest(): void
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'fields' => [
                ['name' => 'items', 'type' => 'object[]'],
                ['name' => 'items.unit_price_net_amount', 'type' => 'int64[]'],
                ['name' => 'items.quantity', 'type' => 'int64[]'],
            ],
        ]);

        $collection->shouldReceive('update')->once()->andThrow(new RuntimeException('boom'));
        $collection->shouldReceive('update')->once()->andReturn([]);

        $result = $this->reconciler($collection)->reconcile($this->model());

        $this->assertCount(1, $result['failed']);
        $this->assertSame('boom', $result['failed'][0]['error']);
        $this->assertCount(1, $result['altered']);
    }

    public function testALossyReTypeIsSkippedUnlessExplicitlyRequested(): void
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'fields' => [
                ['name' => 'items', 'type' => 'object[]'],
                // string -> float[] throws away data, so it is not a widening.
                ['name' => 'items.unit_price_net_amount', 'type' => 'string'],
            ],
        ]);
        $collection->shouldNotReceive('update');

        $reconciler = $this->reconciler($collection);

        $this->assertFalse($reconciler->drift($this->model())[0]['widening']);
        $this->assertSame(['altered' => [], 'failed' => []], $reconciler->reconcile($this->model()));
    }

    public function testAnUnreachableOrMissingCollectionIsLeftAlone(): void
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andThrow(new RuntimeException('Not Found'));
        $collection->shouldNotReceive('update');

        $this->assertSame([], $this->reconciler($collection)->drift($this->model()));
    }

    private function model(?array $fields = null): Model
    {
        $fields ??= [
            ['name' => 'items', 'type' => 'object[]'],
            ['name' => 'items.unit_price_net_amount', 'type' => 'float[]', 'optional' => true],
            ['name' => 'items.quantity', 'type' => 'float[]', 'optional' => true],
        ];

        return new class ($fields) extends Model {
            public function __construct(private array $schemaFields = [])
            {
                parent::__construct();
            }

            public function searchableAs(): string
            {
                return 'reconciler_orders';
            }

            public function typesenseCollectionSchema(): array
            {
                return ['name' => $this->searchableAs(), 'fields' => $this->schemaFields];
            }
        };
    }

    private function liveFields(array $typesByName): TypesenseCollection
    {
        return $this->liveFieldList(array_map(
            fn (string $name, string $type) => ['name' => $name, 'type' => $type],
            array_keys($typesByName),
            array_values($typesByName)
        ));
    }

    private function liveFieldList(array $fields): TypesenseCollection
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('retrieve')->andReturn(['fields' => $fields]);

        return $collection;
    }

    private function reconciler(TypesenseCollection $collection): TypesenseSchemaReconciler
    {
        $collections = Mockery::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('reconciler_orders')->andReturn($collection);

        $client = Mockery::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->andReturn($collections);

        return new TypesenseSchemaReconciler(app(Apps::class), $client);
    }
}
