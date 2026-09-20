<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'browserless_mcp';

    private const string TITLE = 'Browserless';

    private const string DESCRIPTION = 'Browserless over MCP — a cloud browser the agent drives itself: a '
        . 'step-by-step session (goto, snapshot, click, type) for multi-step flows and logins, scraping a '
        . 'page to markdown/HTML/screenshot/PDF, crawling or mapping a site, exporting a page, web search, '
        . 'Lighthouse audits, and running your own Puppeteer code on their cloud. Saved browser profiles '
        . 'keep a site signed in: call browserless_profiles first and pass the profile name to the other '
        . 'tools, so no password goes through the agent. It can also read the account\'s own plan, unit '
        . 'usage, running sessions and request logs. Connect it with your Browserless API token; an '
        . 'invalid token is only reported when a browser is first opened.';

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
