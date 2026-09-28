<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for Google Workspace's MCP servers — one per server, `neuron` only (the
 * Claude Managed Agents bridge does not carry MCP rows yet).
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const array TOOLS = [
        'google_gmail_mcp' => 'Gmail over MCP. Grants the agent every tool the connected Google account allows — searching and reading mail, and drafting messages.',
        'google_drive_mcp' => 'Google Drive over MCP. Grants the agent every tool the connected Google account allows — finding, reading and creating files.',
        'google_calendar_mcp' => 'Google Calendar over MCP. Grants the agent every tool the connected Google account allows — reading calendars and events, and finding free time.',
        'google_sheets_mcp' => 'Google Sheets over MCP. Grants the agent every tool the connected Google account allows — reading and editing spreadsheets.',
        'google_people_mcp' => 'Google People over MCP. Grants the agent every tool the connected Google account allows — looking up contacts and directory profiles.',
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
