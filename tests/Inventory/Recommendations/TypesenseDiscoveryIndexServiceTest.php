<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\Services\TypesenseDiscoveryIndexService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;

class TypesenseDiscoveryIndexServiceTest extends TestCase
{
    public function testPatchesInOnlyTheFieldsTheCollectionIsMissing(): void
    {
        $collection = $this->collection(['name', 'price', 'price_min', 'audience']);
        $collection->shouldReceive('update')
            ->once()
            ->withArgs(function (array $schema): bool {
                $names = array_column($schema['fields'], 'name');

                return $names === ['price_max', 'discovery_schema_version', 'num_.*'];
            });

        $result = $this->service($collection)->ensureFields(dryRun: false, fromDefault: false);

        $this->assertTrue($result['written']);
        $this->assertCount(3, $result['changes']);
    }

    public function testTheFieldDefinitionsComeFromTheModelSchema(): void
    {
        $collection = $this->collection(['name']);
        $collection->shouldReceive('update')
            ->once()
            ->withArgs(function (array $schema): bool {
                $byName = array_column($schema['fields'], null, 'name');

                return $byName['discovery_schema_version']['type'] === 'int32'
                    && $byName['audience']['type'] === 'string[]'
                    && $byName['num_.*']['type'] === 'float';
            });

        $this->service($collection)->ensureFields(dryRun: false, fromDefault: false);
    }

    public function testNothingIsPatchedWhenEveryFieldIsDeclared(): void
    {
        $collection = $this->collection(['price_min', 'price_max', 'discovery_schema_version', 'num_.*', 'audience']);
        $collection->shouldNotReceive('update');

        $result = $this->service($collection)->ensureFields(dryRun: false, fromDefault: false);

        $this->assertSame([], $result['changes']);
        $this->assertFalse($result['written']);
    }

    public function testDryRunListsTheMissingFieldsAndPatchesNothing(): void
    {
        $collection = $this->collection(['name']);
        $collection->shouldNotReceive('update');

        $result = $this->service($collection)->ensureFields(dryRun: true, fromDefault: false);

        $this->assertFalse($result['written']);
        $this->assertCount(5, $result['changes']);
    }

    public function testAMissingCollectionIsNotAnErrorBecauseTheFirstReindexCreatesIt(): void
    {
        $collection = Mockery::mock(Collection::class);
        $collection->shouldReceive('retrieve')->andThrow(new ObjectNotFound('Not found'));
        $collection->shouldNotReceive('update');

        $result = $this->service($collection)->ensureFields(dryRun: false, fromDefault: false);

        $this->assertFalse($result['written']);
        $this->assertStringContainsString('does not exist yet', $result['changes'][0]);
    }

    public function testCoverageScopesBothCountsToTheApp(): void
    {
        $filters = [];
        $documents = Mockery::mock(Documents::class);
        $documents->shouldReceive('search')
            ->andReturnUsing(function (array $params) use (&$filters): array {
                $filters[] = $params['filter_by'];

                return ['found' => 6, 'hits' => []];
            });
        $collection = Mockery::mock(Collection::class);
        $collection->documents = $documents;
        $appId = app(Apps::class)->getId();

        $coverage = $this->service($collection)->coverage(1);

        $this->assertEqualsCanonicalizing(["apps_id:={$appId} && discovery_schema_version:>=1", "apps_id:={$appId}"], $filters);
        $this->assertSame(['total' => 6, 'migrated' => 6, 'stale_ids' => []], $coverage);
    }

    public function testStaleIdsAreListedWhenRecordsAreBehind(): void
    {
        $calls = [];
        $documents = Mockery::mock(Documents::class);
        $documents->shouldReceive('search')
            ->andReturnUsing(function (array $params) use (&$calls): array {
                $calls[] = $params;

                if (str_contains($params['filter_by'], ':<1')) {
                    return ['found' => 2, 'hits' => [['document' => ['id' => '11']], ['document' => ['id' => '12']]]];
                }

                return ['found' => str_contains($params['filter_by'], ':>=1') ? 3 : 5, 'hits' => []];
            });
        $collection = Mockery::mock(Collection::class);
        $collection->documents = $documents;

        $coverage = $this->service($collection)->coverage(1);

        $this->assertSame(['total' => 5, 'migrated' => 3, 'stale_ids' => [11, 12]], $coverage);
        $this->assertSame(50, end($calls)['per_page']);
        $this->assertStringContainsString('apps_id:=' . app(Apps::class)->getId(), end($calls)['filter_by']);
    }

    /**
     * @param list<string> $fields
     */
    private function collection(array $fields): MockInterface
    {
        $collection = Mockery::mock(Collection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'fields' => array_map(static fn (string $name): array => ['name' => $name], $fields),
        ]);

        return $collection;
    }

    private function service(Collection $collection): TypesenseDiscoveryIndexService
    {
        $collections = Mockery::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->andReturn($collection);

        $client = Mockery::mock(Client::class);
        $client->collections = $collections;

        return new TypesenseDiscoveryIndexService(app(Apps::class), $client);
    }
}
