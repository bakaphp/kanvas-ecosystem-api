<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog row for Slack's MCP server — `neuron` only (the Claude Managed Agents bridge does not
 * carry MCP rows yet). Titled `Slack (MCP)` so it cannot be confused with the agent's own Slack channel.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string SLUG = 'slack_mcp';
    private const string TITLE = 'Slack (MCP)';

    public function up(): void
    {
        $integrationId = DB::connection('workflow')->table('integrations')
            ->where('name', self::SLUG)
            ->where('apps_id', 0)
            ->value('id');

        $exists = DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', self::TITLE)
            ->where('apps_id', 0)
            ->exists();

        if ($integrationId === null || $exists) {
            return;
        }

        DB::connection('intelligence')->table('nervous_system_tools')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::TITLE,
            'description' => 'Slack over MCP — the connected Slack workspace as the person who signed in: search messages, files and people, read channels, threads, DMs and canvases, post messages, react, and create or update canvases. It acts with that person\'s identity and access, so anything it posts appears as them — confirm before posting to a channel or messaging someone on their behalf. This is not the agent\'s own Slack bot channel.',
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
            ->where('name', self::TITLE)
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
