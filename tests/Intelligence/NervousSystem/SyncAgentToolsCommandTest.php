<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\UpsertLeadSourceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

class SyncAgentToolsCommandTest extends TestCase
{
    public function testDiscoverDerivesFrameworkAndCategoryFromNamespace(): void
    {
        $byClass = collect(new AgentToolDiscoveryService()->discover())->keyBy('class');

        $laravelTool = $byClass[UpsertLeadSourceTool::class] ?? null;
        $this->assertNotNull($laravelTool, 'Laravel Guild tool should be discovered');
        $this->assertSame(['laravel'], $laravelTool['frameworks']);
        $this->assertSame('crm', $laravelTool['category']);
        $this->assertSame('Upsert Lead Source', $laravelTool['name']);

        $neuronTool = $byClass[HandOffTool::class] ?? null;
        $this->assertNotNull($neuronTool, 'Neuron CRM tool should be discovered');
        $this->assertSame(['neuron'], $neuronTool['frameworks']);
        $this->assertSame('crm', $neuronTool['category']);
    }

    public function testSyncCreatesGlobalToolRow(): void
    {
        $this->artisan('kanvas:nervous-system:sync-tools')->assertSuccessful();

        $this->assertDatabaseHas(
            'nervous_system_tools',
            [
                'handler' => HandOffTool::class,
                'apps_id' => 0,
            ],
            'intelligence',
        );
    }

    public function testSyncIsIdempotent(): void
    {
        $this->artisan('kanvas:nervous-system:sync-tools')->assertSuccessful();
        $countAfterFirst = $this->toolCount();

        $this->artisan('kanvas:nervous-system:sync-tools')->assertSuccessful();
        $countAfterSecond = $this->toolCount();

        $this->assertGreaterThanOrEqual(1, $countAfterFirst);
        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'Re-running the sync must not create duplicate tool rows',
        );
    }

    private function toolCount(): int
    {
        return Tool::query()->where('apps_id', 0)->count();
    }
}
