<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for the ad-platform MCP servers — `neuron` only (the Claude Managed Agents
 * bridge does not carry MCP rows yet).
 *
 * Both descriptions state what the agent may NOT do, because an agent that believes it can pause a
 * campaign and cannot is worse than one that knows its limits: Google's server is read-only, and Meta
 * creates everything paused.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'meta_ads_mcp' => [
            'Meta Ads',
            'Meta Ads over MCP — Facebook and Instagram advertising on the connected Business Manager account: reporting and insights, campaign management, catalogue operations and account diagnostics. Every campaign, ad set and ad it creates is left PAUSED, so nothing starts spending without a person resuming it. Connecting is one click; Meta needs no developer app.',
        ],
        'google_ads_mcp' => [
            'Google Ads',
            'Google Ads over MCP — READ ONLY: discovering accounts, running GAQL reports and reading resource metadata. It cannot change bids, pause campaigns or create assets, so never promise a change here. Runs on the Google Ads MCP server your company hosts (Google publishes no hosted one); connect it with that deployment\'s https address and its token.',
        ],
    ];

    public function up(): void
    {
        foreach (self::TOOLS as $slug => [$title, $description]) {
            $integrationId = DB::connection('workflow')->table('integrations')
                ->where('name', $slug)
                ->where('apps_id', 0)
                ->value('id');

            $exists = DB::connection('intelligence')->table('nervous_system_tools')
                ->where('name', $title)
                ->where('apps_id', 0)
                ->exists();

            if ($integrationId === null || $exists) {
                continue;
            }

            DB::connection('intelligence')->table('nervous_system_tools')->insert([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => $title,
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
            ->whereIn('name', array_column(self::TOOLS, 0))
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
