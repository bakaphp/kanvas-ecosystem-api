<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Zoho's MCP server, which spans CRM, Mail, Calendar, Desk, Cliq, Projects, WorkDrive and Books, and acts
 * under the permissions of whoever signs in.
 *
 * The address is generated per account in Zoho's MCP Console ("Copy the MCP URL") — Zoho publishes no
 * fixed pattern, and the generated URL already carries the account's data centre (.com / .eu / .in) — so
 * this takes `url_per_connection` like Salesforce and SAP.
 *
 * OAuth is the documented method; `bearer` stays available because a console-issued URL can be paired
 * with a token instead. If a generated endpoint turns out not to advertise RFC 9728 metadata, add
 * `metadata.oauth.authorization_server` for it, the way Atlassian needed.
 *
 * Kanvas already has a native Zoho connector; this row is the per-agent alternative, and an agent should
 * hold one or the other.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'zoho_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'zoho_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Zoho',
                'transport' => 'http',
                'auth_methods' => ['oauth', 'bearer'],
                'prefix' => 'zoho',
                'exclude' => [],
                'timeout_ms' => 20000,
                'url_per_connection' => true,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'zoho_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
