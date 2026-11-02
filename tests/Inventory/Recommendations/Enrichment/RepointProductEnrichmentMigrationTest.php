<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations\Enrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * `agent_types` keys on the handler class, so the move to Inventory\Recommendations\Enrichment must keep
 * the row every Agent points at and fold in any row a sync created first.
 */
#[Group('serial')]
class RepointProductEnrichmentMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    private const string OLD_AGENT = 'Kanvas\\Connectors\\ProductEnrichment\\Agents\\ProductEnrichmentAgent';
    private const string NEW_AGENT = 'Kanvas\\Inventory\\Recommendations\\Enrichment\\Agents\\ProductEnrichmentAgent';

    public function testKeepsTheOldAgentTypeAndFoldsInASyncCreatedDuplicate(): void
    {
        $db = DB::connection('intelligence');
        $db->table('agent_types')->whereIn('handler', [self::OLD_AGENT, self::NEW_AGENT])->update(['handler' => 'Moved\\Aside']);

        $kept = $this->agentType(self::OLD_AGENT);
        $duplicate = $this->agentType(self::NEW_AGENT);
        $secondOldOfSameApp = $this->agentType(self::OLD_AGENT);
        $agentId = Agent::factory()
            ->withAppId(1)
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $duplicate])
            ->getId();

        $this->migration('Intelligence/2026_10_20_200000_repoint_product_enrichment_agent_type.php')->up();

        $this->assertSame(self::NEW_AGENT, $db->table('agent_types')->where('id', $kept)->value('handler'));
        $this->assertSame(self::NEW_AGENT, $db->table('agent_types')->where('id', $secondOldOfSameApp)->value('handler'), 'Old rows never fold into each other.');
        $this->assertNull($db->table('agent_types')->where('id', $duplicate)->value('id'));
        $this->assertSame($kept, (int) $db->table('agents')->where('id', $agentId)->value('agent_type_id'), 'The duplicate\'s agents move to the kept row.');
    }

    private function migration(string $path): object
    {
        return require base_path('database/migrations/' . $path);
    }

    /**
     * Cloned from an existing row through the query builder: saving through the model would run
     * AllowedClass::assert(), and the old class name no longer exists — which is the point.
     */
    private function agentType(string $handler): int
    {
        $row = (array) DB::connection('intelligence')->table('agent_types')->orderBy('id')->first();
        unset($row['id']);

        return (int) DB::connection('intelligence')->table('agent_types')->insertGetId([
            ...$row,
            'apps_id' => 1,
            'uuid' => (string) Str::uuid(),
            'name' => 'repoint test',
            'handler' => $handler,
            'is_deleted' => 0,
        ]);
    }
}
