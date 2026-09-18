<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Lands inactive: a fake token gets 403 "Unauthorized Access", which cannot tell a bad token apart from
 * Meta refusing every client it has not allow-listed. Flip `is_active` once a real system-user token
 * completes `tools/list` — offering a server that always fails is worse than offering none.
 *
 * Titled `(MCP)` so it never resolves ambiguously against a company's native WhatsApp channel.
 */
return new class () extends Migration {
    private const string SLUG = 'whatsapp_business_mcp';

    private const string TITLE = 'WhatsApp Business (MCP)';

    private const string DESCRIPTION = 'WhatsApp Business over MCP — listing the businesses, WhatsApp '
        . 'accounts and phone numbers the token can reach, onboarding and registering a number, managing '
        . 'message templates, and sending text or template messages. A template sent outside the 24-hour '
        . 'window is billed by Meta and new templates go through Meta review, so send and create on an '
        . 'explicit request.';

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
