<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Product enrichment moved out of Connectors (it calls no outside service and writes the
 * `search_blurb` discovery reads) into Inventory\Recommendations\Enrichment.
 *
 * `agent_types.handler` stores the class name and `kanvas:intelligence:sync-agent-types` keys on it,
 * so without this the sync would create a second "Product Enrichment" type and every existing
 * enrichment Agent would stay on a type whose handler no longer exists.
 *
 * The OLD row is kept and renamed — it is the one every Agent and tool grant points at. If the sync
 * already created a row on the new class (deploy order), that row is folded into the old one and
 * removed, so this is safe to run before or after the sync, and to re-run.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string OLD = 'Kanvas\\Connectors\\ProductEnrichment\\Agents\\ProductEnrichmentAgent';
    private const string NEW = 'Kanvas\\Inventory\\Recommendations\\Enrichment\\Agents\\ProductEnrichmentAgent';

    public function up(): void
    {
        $this->repoint(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->repoint(self::NEW, self::OLD);
    }

    private function repoint(string $from, string $to): void
    {
        $db = DB::connection('intelligence');

        // Snapshot first: rows renamed inside the loop must never be mistaken for a sync-created
        // duplicate, or several old rows of one app would fold into each other.
        $duplicates = [];
        foreach ($db->table('agent_types')->where('handler', $to)->get(['id', 'apps_id', 'is_deleted']) as $row) {
            $duplicates[$row->apps_id . ':' . $row->is_deleted] ??= $row->id;
        }

        foreach ($db->table('agent_types')->where('handler', $from)->get(['id', 'apps_id', 'is_deleted']) as $kept) {
            $key = $kept->apps_id . ':' . $kept->is_deleted;
            $duplicate = $duplicates[$key] ?? null;
            unset($duplicates[$key]);

            if ($duplicate !== null) {
                $db->table('agents')->where('agent_type_id', $duplicate)->update(['agent_type_id' => $kept->id]);

                $granted = $db->table('nervous_system_tool_agent_types')->where('agent_type_id', $kept->id)->pluck('tool_id')->all();
                $db->table('nervous_system_tool_agent_types')
                    ->where('agent_type_id', $duplicate)
                    ->when($granted !== [], fn ($query) => $query->whereNotIn('tool_id', $granted))
                    ->update(['agent_type_id' => $kept->id]);
                $db->table('nervous_system_tool_agent_types')->where('agent_type_id', $duplicate)->delete();

                $db->table('agent_types')->where('id', $duplicate)->delete();
            }

            $db->table('agent_types')->where('id', $kept->id)->update(['handler' => $to]);
        }
    }
};
