<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Meta's "Social Technologies" server (`/devtools`): the Meta apps a developer account administers —
 * settings, App Review status, compliance, API usage and health, webhook topics and subscriptions, plus
 * a search over Meta's developer documentation.
 *
 * Probed 2026-10-13: `initialize` → 401 naming `oauth-protected-resource/devtools` → authorization server
 * `https://www.facebook.com/devtools`, whose RFC 8414 metadata lists the standard Facebook Login dialog
 * and Graph token endpoint, PKCE S256, refresh tokens, public clients (`none`) and a registration
 * endpoint that answers "Dynamic registration is not available for this client" to every payload — the
 * same allow-list as Meta Ads and WhatsApp. Hence `client_key: meta`: one hand-made Meta app shared by
 * the three Meta rows, set on the app as `mcp_oauth_client_id_meta` (Meta's docs name the app id as the
 * client id; the secret is optional since the token endpoint accepts `none`). A user access token
 * carrying the same permissions is the bearer alternative.
 *
 * Only the read half (`developer_tools_mcp_app_read`) is requested. The manage scope adds nothing but
 * webhook writes — and a Meta app wired to Kanvas receives WhatsApp, Messenger and Instagram events
 * through exactly those subscriptions, so one agent call could silently repoint or delete them. The
 * three tools that need or feed it are excluded: `webhook_manage`, `webhook_test` and
 * `skill_invocation` (analytics for Meta's own skill workflows, useless inside a turn).
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'meta_devtools_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'meta_devtools_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Meta',
                'transport' => 'http',
                'url' => 'https://mcp.facebook.com/devtools',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'oauth' => [
                    'client_key' => 'meta',
                    'scopes' => [
                        'developer_tools_mcp_app_read',
                        'business_management',
                    ],
                ],
                'prefix' => 'metadev',
                'exclude' => [
                    'devtools_webhook_manage',
                    'devtools_webhook_test',
                    'devtools_skill_invocation',
                ],
                'timeout_ms' => 30000,
                'url_per_connection' => false,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'meta_devtools_mcp')
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
