<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Mailgun's official server (`@mailgun/mcp-server`) is stdio only — Mailgun hosts nothing — so like
 * Playwright it takes an address per connection. A company wraps it in an HTTP bridge it runs itself:
 *
 *     MAILGUN_API_KEY=... npx -y supergateway --stdio "npx -y @mailgun/mcp-server" \
 *         --outputTransport streamableHttp --port 8000
 *
 * and serves `/mcp` over https. The Mailgun key lives on that bridge, never with us. Bearer only, no
 * `none`: the bridge sends mail as the whole account, so an open address would be an open relay.
 *
 * Verified 2026-09-18 against 2.1.3 through that bridge: all 74 tools list over streamable HTTP, and
 * every name fits 64 characters under the prefix.
 *
 * Excluded on purpose — the account-wide settings Kanvas's own Mailgun wiring depends on:
 *  - webhook create/update — repointing an event webhook silently cuts off delivery/bounce tracking;
 *  - route update — agent inboxes and email routes are Mailgun routes (`CreateEmailRouteTool`), and a
 *    rewritten route stops inbound mail reaching the agent;
 *  - open/click/unsubscribe tracking toggles — they change every message the domain sends, not one.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'mailgun_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'mailgun_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Mailgun',
                'transport' => 'http',
                'auth_methods' => ['bearer'],
                'prefix' => 'mailgun',
                'exclude' => [
                    'post-v3-domains-domain-webhooks',
                    'put-v3-domains-domain-webhooks-webhook',
                    'put-v3-routes-id',
                    'put-v3-domains-name-tracking-click',
                    'put-v3-domains-name-tracking-open',
                    'put-v3-domains-name-tracking-unsubscribe',
                ],
                'timeout_ms' => 30000,
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
            ->where('name', 'mailgun_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
