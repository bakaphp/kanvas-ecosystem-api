<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'browser_use_mcp';

    private const string TITLE = 'Browser Use';

    private const string DESCRIPTION = 'Browser Use over MCP — hand a web task to Browser Use\'s cloud browsing '
        . 'agent: it opens pages, clicks, fills forms and extracts data, and returns the result, optionally '
        . 'shaped by an output schema. Start with run_session, then poll get_session for status and output; '
        . 'keep_alive sessions take follow-up tasks through send_task. For sites that need a login, use a '
        . 'browser profile (list_browser_profiles → profile_id) that someone logged into once, so no password '
        . 'is ever sent through the agent. Connect it with your Browser Use API key; an invalid key is only '
        . 'reported when a session is first run. Each session is billed to that Browser Use account.';

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
