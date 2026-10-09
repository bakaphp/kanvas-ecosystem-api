<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'kias_mcp';

    private const string TITLE = 'KIAS';

    private const string DESCRIPTION = 'KIAS over MCP — administrative task tracking, message deduplication, '
        . 'Gmail/Slack thread linking, task updates and deadline review for the organization bound to '
        . 'the supplied bearer token. Connect with your KIAS server\'s full HTTPS endpoint ending in '
        . '/mcp/kias and that organization\'s KIAS MCP token, not a Neuron API key. Every write requires '
        . 'an idempotency_key; reuse the same key and arguments on retry. This grant excludes procurement '
        . 'tools and does not read Gmail, send Slack messages or schedule agent executions.';

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
