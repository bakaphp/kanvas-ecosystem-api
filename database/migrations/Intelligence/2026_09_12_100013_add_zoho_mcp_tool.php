<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog row for Zoho's MCP server — `neuron` only (the Claude Managed Agents bridge does
 * not carry MCP rows yet).
 */
return new class () extends Migration {
    private const string SLUG = 'zoho_mcp';

    private const string TITLE = 'Zoho';

    private const string DESCRIPTION = 'Zoho over MCP — whatever the connected Zoho account can reach '
        . 'across CRM, Mail, Calendar, Desk, Cliq, Projects, WorkDrive and Books, acting under that '
        . 'user\'s own permissions. Connect it with the address generated in the Zoho MCP Console. Kanvas '
        . 'also ships a native Zoho connector that runs on one company-wide credential; grant that OR '
        . 'this, not both, so the agent is not offered the same capability twice.';

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
