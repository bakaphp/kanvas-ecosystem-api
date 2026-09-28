<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Titled `Mailgun (MCP)` because Kanvas already sends and receives through Mailgun natively; a bare
 * `Mailgun` would read as that wiring rather than the company's own account.
 */
return new class () extends Migration {
    private const string SLUG = 'mailgun_mcp';

    private const string TITLE = 'Mailgun (MCP)';

    private const string DESCRIPTION = 'Mailgun over MCP — the company\'s own Mailgun account: sending '
        . 'and resending email, listing and verifying domains, delivery stats and logs broken down by '
        . 'tag, provider, device and country, bounce/unsubscribe/complaint lookups, templates and their '
        . 'versions, mailing lists and members, IPs and IP pools, and email validation. Runs on a server '
        . 'your company hosts: wrap `npx -y @mailgun/mcp-server` in an HTTP bridge (e.g. `npx supergateway '
        . '--stdio "npx -y @mailgun/mcp-server" --outputTransport streamableHttp`) with MAILGUN_API_KEY set '
        . 'there, put it behind https with a bearer token, and connect with that address and token. '
        . 'Webhooks, routes and tracking settings cannot be changed through it.';

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
