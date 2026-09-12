<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog row for TikTok for Business — `neuron` only (the Claude Managed Agents bridge
 * does not carry MCP rows yet).
 *
 * The description states the write capability outright. Meta's server pauses everything it creates;
 * TikTok documents no such guarantee, so an agent holding this can move real money.
 */
return new class () extends Migration {
    private const string SLUG = 'tiktok_ads_mcp';

    private const string TITLE = 'TikTok Ads';

    private const string DESCRIPTION = 'TikTok for Business over MCP — advertising on the connected TikTok '
        . 'Business account: performance reporting, campaign management, audience targeting and creative '
        . 'operations. This one WRITES: it can create campaigns, change budgets and pause ad groups, and '
        . 'TikTok does not force new objects to start paused — confirm spend-affecting changes with the '
        . 'user before making them. Tools are disclosed progressively rather than all at once.';

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
