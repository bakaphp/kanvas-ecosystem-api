<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Lands inactive: Vercel only registers clients whose redirect URI it has approved, so until a
 * hand-made client exists under `mcp_oauth_client_id_vercel` every connect attempt dies at
 * registration with Vercel's `invalid_redirect_uri`. Offering a server that cannot connect is worse
 * than not offering it. Flip `is_active` once a real account completes `tools/list`.
 */
return new class () extends Migration {
    private const string SLUG = 'vercel_mcp';

    private const string TITLE = 'Vercel';

    private const string DESCRIPTION = 'Vercel over MCP — searching Vercel documentation, listing the '
        . 'teams and projects the account can reach, inspecting deployments with their build logs, '
        . 'runtime logs and runtime errors, querying Web Analytics visitors, page views and custom '
        . 'events, reading Agent Runs and their traces, checking domain availability and price, fetching '
        . 'a page from one of the team\'s own deployments, and reading or replying to toolbar comment '
        . 'threads. Read-and-observe only: deploying, buying (plans, credits, add-ons, domains) and '
        . 'minting deployment-protection bypass links are not exposed.';

    protected $connection = 'intelligence';

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
            'description' => self::DESCRIPTION,
            'tool_type' => ToolTypeEnum::MCP->value,
            'handler' => null,
            'integrations_id' => $integrationId,
            'frameworks' => json_encode(['neuron']),
            'version' => '1.0.0',
            'is_active' => 0,
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
