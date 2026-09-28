<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The catalog entry an admin grants to an agent. ONE row for the whole server — the methods it exposes
 * are read live from `tools/list` and are deliberately never catalog rows, so a vendor renaming a tool
 * cannot leave a stale grant behind.
 *
 * `handler` stays null: this row is backed by the `integrations` row, the same way a SUB_AGENT row is
 * backed by `agents_id`.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string NAME = 'atlassian_mcp';

    public function up(): void
    {
        $integrationId = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->value('id');

        if ($integrationId === null) {
            return;
        }

        $exists = DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('intelligence')->table('nervous_system_tools')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::NAME,
            'description' => 'Atlassian (Jira + Confluence) over MCP. Grants the agent every tool the connected Atlassian account exposes — searching and reading issues, creating and transitioning them, and reading Confluence pages.',
            'tool_type' => ToolTypeEnum::MCP->value,
            'handler' => null,
            'integrations_id' => $integrationId,
            'frameworks' => json_encode(['neuron', 'claude']),
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->delete();
    }
};
