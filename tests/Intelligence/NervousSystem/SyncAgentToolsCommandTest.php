<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\UpsertLeadSourceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderBreakdownTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\WhoIsUserTool;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;
use stdClass;
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
        // A Neuron tool is deliberately tagged `claude` as well — a hosted Claude agent runs the very
        // same object through the custom-tool bridge, and the grant UI filters by provider, so without
        // the second tag it could not be granted any tool. See withHostedFrameworks().
        $this->assertSame(['neuron', 'claude'], $neuronTool['frameworks']);
        $this->assertSame('crm', $neuronTool['category']);

        $accountingTool = $byClass[FindInvoiceTool::class] ?? null;
        $this->assertNotNull($accountingTool, 'Neuron Accounting tool should be discovered');
        $this->assertSame('accounting', $accountingTool['category']);

        $systemTool = $byClass[WhoIsUserTool::class] ?? null;
        $this->assertNotNull($systemTool, 'Neuron System tool should be discovered');
        $this->assertSame('ecosystem', $systemTool['category']);
    }

    public function testAgentToolFromClassReadsAttribute(): void
    {
        $meta = AgentTool::fromClass(HandOffTool::class);

        $this->assertNotNull($meta);
        $this->assertSame('Hand Off Lead', $meta->name);

        $this->assertNull(
            AgentTool::fromClass(stdClass::class),
            'A class without the attribute returns null',
        );
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

    public function testForceBackfillsCategoryOnExistingRows(): void
    {
        $this->artisan('kanvas:nervous-system:sync-tools')->assertSuccessful();

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('handler', OrderBreakdownTool::class)
            ->where('apps_id', 0)
            ->firstOrFail();

        $tool->tool_category_id = null;
        $tool->save();

        // Without --force the nulled category stays null (create-only path).
        $this->artisan('kanvas:nervous-system:sync-tools')->assertSuccessful();
        $this->assertNull($tool->fresh()->tool_category_id);

        $this->artisan('kanvas:nervous-system:sync-tools', ['--force' => true])->assertSuccessful();

        $categoryId = $tool->fresh()->tool_category_id;
        $this->assertNotNull($categoryId);
        $this->assertSame('commerce', ToolCategory::query()->whereKey($categoryId)->value('slug'));
    }

    public function testPruneDeletesUnreferencedDuplicatesButKeepsInUseAndSurvivor(): void
    {
        $this->artisan('kanvas:nervous-system:sync-tools')->assertSuccessful();

        /** @var Tool $canonical */
        $canonical = Tool::query()
            ->where('handler', HandOffTool::class)
            ->where('apps_id', 0)
            ->firstOrFail();

        // Two extra duplicate rows with the same handler: one referenced (in use), one orphan.
        $referenced = $this->cloneToolRow($canonical);
        $orphan = $this->cloneToolRow($canonical);

        DB::connection('intelligence')->table('nervous_system_tool_agent_types')->insert([
            'tool_id' => $referenced->id,
            'agent_type_id' => 999999,
            'created_at' => now(),
        ]);

        $this->artisan('kanvas:nervous-system:sync-tools', ['--prune' => true])->assertSuccessful();

        $this->assertDatabaseHas('nervous_system_tools', ['id' => $canonical->id], 'intelligence');
        $this->assertDatabaseHas('nervous_system_tools', ['id' => $referenced->id], 'intelligence');
        $this->assertDatabaseMissing('nervous_system_tools', ['id' => $orphan->id], 'intelligence');
    }

    private function cloneToolRow(Tool $source): Tool
    {
        $clone = $source->replicate();
        $clone->uuid = null;
        $clone->tool_category_id = null;
        $clone->save();

        return $clone;
    }

    private function toolCount(): int
    {
        return Tool::query()->where('apps_id', 0)->count();
    }
}
