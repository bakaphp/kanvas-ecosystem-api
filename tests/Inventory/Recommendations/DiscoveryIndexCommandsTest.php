<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\DiscoveryIndexAdapter;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\DiscoveryIndexAdapterResolverService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class DiscoveryIndexCommandsTest extends TestCase
{
    use ManagesAppSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(ConfigurationEnum::RANGE_FILTERS->value);
        app(Apps::class)->del(ConfigurationEnum::RANGE_FILTERS->value);
    }

    public function testPrepareSaysThereIsNothingToDoOnTheSqlFallback(): void
    {
        $this->bindAdapter(null);

        $this->artisan('kanvas-inventory:prepare-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->expectsOutputToContain('Nothing to prepare on the SQL fallback.')
            ->assertExitCode(0);
    }

    public function testPrepareDryRunPrintsTheDiffAndAsksTheIndexToWriteNothing(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->once()->with(true, false)->andReturn($this->plan(['searchableAttributes: append search_blurb (last)']));
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:prepare-discovery-index', [
            'app_id' => app(Apps::class)->getId(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('searchableAttributes: append search_blurb (last)')
            ->assertExitCode(0);
    }

    public function testPrepareWritesWithoutAskingWhenNonInteractive(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->once()->ordered()->with(true, false)->andReturn($this->plan(['add field price_min']));
        $adapter->shouldReceive('ensureFields')->once()->ordered()->with(false, false)->andReturn($this->plan(['add field price_min'], written: true));
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:prepare-discovery-index', [
            'app_id' => app(Apps::class)->getId(),
            '--no-interaction' => true,
        ])->assertExitCode(0);
    }

    public function testPrepareWritesNothingWhenTheOperatorDeclines(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->once()->with(true, false)->andReturn($this->plan(['add field price_min']));
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:prepare-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->expectsConfirmation('Write these changes to the index?', 'no')
            ->assertExitCode(1);
    }

    public function testPrepareReportsARefusalAndTheProposedListAndFails(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->once()->with(true, false)->andReturn([
            'index' => 'product_index',
            'changes' => [],
            'written' => false,
            'refusal' => 'searchableAttributes is empty',
            'proposed' => ['name', 'search_blurb'],
        ]);
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:prepare-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->expectsOutputToContain('Proposed searchableAttributes: name, search_blurb')
            ->assertExitCode(1);
    }

    public function testVerifySwitchesRangeFiltersOnAtFullCoverage(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('coverage')->once()->with(Products::DISCOVERY_SCHEMA_VERSION)->andReturn(['total' => 10, 'migrated' => 10, 'stale_ids' => []]);
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:verify-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->assertExitCode(0);

        $this->assertSame(Products::DISCOVERY_SCHEMA_VERSION, (int) app(Apps::class)->get(ConfigurationEnum::RANGE_FILTERS->value));
    }

    public function testVerifyLeavesTheSwitchAloneAndListsStaleIdsWhenRecordsAreBehind(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('coverage')->once()->andReturn(['total' => 10, 'migrated' => 7, 'stale_ids' => [3, 4]]);
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:verify-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->expectsOutputToContain('3, 4')
            ->assertExitCode(1);

        $this->assertNull(app(Apps::class)->get(ConfigurationEnum::RANGE_FILTERS->value));
    }

    public function testVerifyRefusesAnEmptyIndexEvenThoughNothingIsStale(): void
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('coverage')->once()->andReturn(['total' => 0, 'migrated' => 0, 'stale_ids' => []]);
        $this->bindAdapter($adapter);

        $this->artisan('kanvas-inventory:verify-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->assertExitCode(1);

        $this->assertNull(app(Apps::class)->get(ConfigurationEnum::RANGE_FILTERS->value));
    }

    public function testVerifyDoesNothingOnTheSqlFallback(): void
    {
        $this->bindAdapter(null);

        $this->artisan('kanvas-inventory:verify-discovery-index', ['app_id' => app(Apps::class)->getId()])
            ->expectsOutputToContain('Nothing to verify on the SQL fallback.')
            ->assertExitCode(0);

        $this->assertNull(app(Apps::class)->get(ConfigurationEnum::RANGE_FILTERS->value));
    }

    private function adapter(): MockInterface
    {
        return Mockery::mock(DiscoveryIndexAdapter::class);
    }

    private function bindAdapter(?DiscoveryIndexAdapter $adapter): void
    {
        $resolver = Mockery::mock(DiscoveryIndexAdapterResolverService::class);
        $resolver->shouldReceive('resolve')->andReturn($adapter);
        $this->app->instance(DiscoveryIndexAdapterResolverService::class, $resolver);
    }

    /**
     * @param list<string> $changes
     *
     * @return array{index: string, changes: list<string>, written: bool, refusal: ?string, proposed: list<string>}
     */
    private function plan(array $changes, bool $written = false): array
    {
        return [
            'index' => 'product_index',
            'changes' => $changes,
            'written' => $written,
            'refusal' => null,
            'proposed' => [],
        ];
    }
}
