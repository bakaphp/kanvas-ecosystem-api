<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Seeded inactive on purpose: one read key reads every dealer's telemetry in its environment, so the tool
 * stays out of agents' turns until someone turns it on per environment (`is_active = 1`). That is not an
 * access barrier — connecting resolves the tool by id without checking `is_active`.
 *
 * Titled `SalesAssist Console (MCP)` because grants resolve by title and the bare product name would read as
 * the CRM connector rather than the telemetry server.
 */
return new class () extends Migration {
    private const string SLUG = 'salesassist_console_mcp';

    private const string TITLE = 'SalesAssist Console (MCP)';

    private const string DESCRIPTION = 'SalesAssist Console over MCP — read-only telemetry from the events the '
        . 'browser extension and the mobile app send: search events by time range, name, status, rep, company, '
        . 'branch or trace, count and time them grouped by name, day, hour or an attribute, walk a trace as a '
        . 'span tree, list traces, replay one session or one rep\'s sessions, poll the newest events, and look up '
        . 'what an event name means in the event catalog. It holds no CRM, lead or inventory data, and emails and '
        . 'personal values come back hidden. Answers are plain text. Connect it with the Console\'s /mcp address '
        . '(https) and a Console read key; the key\'s prefix decides whether it reads production or staging.';

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
