<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Search\AlgoliaSettingsReconciler;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class AlgoliaSettingsReconcilerTest extends TestCase
{
    private const DECLARED = [
        'searchableAttributes' => ['name', 'sku'],
        'attributesForFaceting' => ['status.name'],
    ];

    public function testAFreshIndexGetsEveryDeclaredSetting(): void
    {
        $client = $this->client(['searchableAttributes' => [], 'attributesForFaceting' => []]);
        $client->shouldReceive('setSettings')
            ->once()
            ->with('product_index', self::DECLARED, true)
            ->andReturn([]);

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model());

        $this->assertNull($result['error']);
        $this->assertSame(self::DECLARED, $result['applied']);
    }

    public function testAnIndexTunedInTheDashboardIsLeftAlone(): void
    {
        $client = $this->client([
            'searchableAttributes' => ['unordered(name)', 'description'],
            'attributesForFaceting' => ['brand'],
        ]);
        $client->shouldNotReceive('setSettings');

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model());

        $this->assertSame([], $result['applied']);
        $this->assertNull($result['error']);
    }

    public function testOnlyTheSettingsTheIndexIsMissingAreFilledIn(): void
    {
        $client = $this->client(['searchableAttributes' => ['unordered(name)']]);
        $client->shouldReceive('setSettings')
            ->once()
            ->with('product_index', ['attributesForFaceting' => ['status.name']], true)
            ->andReturn([]);

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model());

        $this->assertSame(['attributesForFaceting' => ['status.name']], $result['applied']);
    }

    public function testForceOverwritesWhatTheIndexAlreadyHas(): void
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldNotReceive('getSettings');
        $client->shouldReceive('setSettings')
            ->once()
            ->with('product_index', self::DECLARED, true)
            ->andReturn([]);

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model(), force: true);

        $this->assertSame(self::DECLARED, $result['applied']);
    }

    public function testAnIndexThatDoesNotExistYetIsTreatedAsEmpty(): void
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('getSettings')->andThrow(new RuntimeException('Index product_index does not exist'));
        $client->shouldReceive('setSettings')
            ->once()
            ->with('product_index', self::DECLARED, true)
            ->andReturn([]);

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model());

        $this->assertSame(self::DECLARED, $result['applied']);
    }

    public function testAModelWithoutDeclaredSettingsNeverTouchesTheIndex(): void
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldNotReceive('getSettings');
        $client->shouldNotReceive('setSettings');

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model([]));

        $this->assertSame(['applied' => [], 'error' => null], $result);
    }

    public function testARejectedSettingsCallIsReportedNotThrown(): void
    {
        $client = $this->client([]);
        $client->shouldReceive('setSettings')->andThrow(new RuntimeException('Invalid attribute'));

        $result = new AlgoliaSettingsReconciler($client)->reconcile($this->model());

        $this->assertSame([], $result['applied']);
        $this->assertSame('Invalid attribute', $result['error']);
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $client = $this->client([]);
        $client->shouldNotReceive('setSettings');

        $this->assertSame(self::DECLARED, new AlgoliaSettingsReconciler($client)->missing($this->model()));
    }

    private function client(array $liveSettings): MockInterface
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('getSettings')->with('product_index')->andReturn($liveSettings);

        return $client;
    }

    private function model(array $settings = self::DECLARED): Model
    {
        $model = new class () extends Model {
            public array $indexSettings = [];

            public function searchableAs(): string
            {
                return 'product_index';
            }

            public function algoliaIndexSettings(): array
            {
                return $this->indexSettings;
            }
        };

        $model->indexSettings = $settings;

        return $model;
    }
}
