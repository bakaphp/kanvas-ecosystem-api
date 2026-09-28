<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog row for GitHub's MCP server — one row for the whole server.
 *
 * `neuron` only: the Claude Managed Agents bridge does not carry MCP rows yet (PR5), and advertising
 * `claude` would let an admin grant something that silently does nothing.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string NAME = 'github_mcp';

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
            'description' => 'GitHub over MCP. Grants the agent every tool the connected token allows — reading repositories, issues and pull requests, and opening or commenting on them.',
            'tool_type' => ToolTypeEnum::MCP->value,
            'handler' => null,
            'integrations_id' => $integrationId,
            'frameworks' => json_encode(['neuron']),
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
