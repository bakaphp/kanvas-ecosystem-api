<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for the browser-automation MCP servers — `neuron` only (the Claude Managed
 * Agents bridge does not carry MCP rows yet).
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const array TOOLS = [
        'browserbase_mcp' => 'Browserbase over MCP — a cloud browser the agent drives: opening pages, reading them, filling forms and extracting data. Connect it with the full server URL including your Browserbase API key; an invalid key is only reported when a browser is first opened.',
        'playwright_mcp' => 'Playwright over MCP — browser automation against a Playwright MCP server your company hosts (`npx @playwright/mcp --port`). Connect it with that server\'s public https address, and a bearer token if you put one in front of it.',
    ];

    public function up(): void
    {
        foreach (self::TOOLS as $name => $description) {
            $integrationId = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->value('id');

            $exists = DB::connection('intelligence')->table('nervous_system_tools')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($integrationId === null || $exists) {
                continue;
            }

            DB::connection('intelligence')->table('nervous_system_tools')->insert([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => $name,
                'description' => $description,
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
    }

    public function down(): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->whereIn('name', array_keys(self::TOOLS))
            ->where('apps_id', 0)
            ->delete();
    }
};
