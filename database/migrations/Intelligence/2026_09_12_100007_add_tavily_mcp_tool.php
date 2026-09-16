<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog row for Tavily's MCP server — `neuron` only (the Claude Managed Agents bridge
 * does not carry MCP rows yet).
 */
return new class () extends Migration {
    private const string NAME = 'tavily_mcp';

    private const string DESCRIPTION = 'Tavily over MCP — web search and research on the connected Tavily '
        . 'account: searching the live web, extracting a page\'s content, and crawling or mapping a site. '
        . 'Kanvas also ships built-in Tavily tools that run on one company-wide key; grant those OR this, '
        . 'not both, so the agent is not offered the same capability twice.';

    protected $connection = 'intelligence';

    public function up(): void
    {
        $integrationId = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->value('id');

        $exists = DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($integrationId === null || $exists) {
            return;
        }

        DB::connection('intelligence')->table('nervous_system_tools')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::NAME,
            'description' => self::DESCRIPTION,
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
