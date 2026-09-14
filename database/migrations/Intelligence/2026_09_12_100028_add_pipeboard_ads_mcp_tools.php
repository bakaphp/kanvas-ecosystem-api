<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for the Pipeboard-brokered ad platforms — `neuron` only (the Claude Managed
 * Agents bridge does not carry MCP rows yet).
 *
 * Each title names the broker, because an admin granting this is choosing to let a third party hold the
 * advertiser's platform credentials, and that should not be buried in a description.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'pipeboard_meta_ads_mcp' => [
            'Meta Ads (Pipeboard)',
            'Meta Ads (Facebook and Instagram) through Pipeboard, a Meta Business Partner that brokers the connection Meta\'s own MCP server will not give us: reporting and insights, campaign and ad set management, creatives and audience targeting. This one CAN WRITE — confirm any budget, bid or status change with the user before making it. The advertiser signs in to Pipeboard, which holds the Meta credentials on its servers.',
        ],
        'pipeboard_google_ads_mcp' => [
            'Google Ads (Pipeboard)',
            'Google Ads through Pipeboard, which needs no developer token and nothing self-hosted: account discovery, performance reporting and campaign data. Granted READ ONLY here, so it cannot change bids, budgets or campaign status — say so plainly rather than promising a change. The advertiser signs in to Pipeboard, which holds the Google credentials on its servers.',
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
