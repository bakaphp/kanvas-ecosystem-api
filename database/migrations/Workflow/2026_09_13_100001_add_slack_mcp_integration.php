<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Slack's official MCP server. Its metadata offers no registration endpoint and authenticates clients with
 * `client_secret_post`, so it needs a hand-made Slack app stored as `mcp_oauth_client_id_slack` /
 * `mcp_oauth_client_secret_slack`. The app must have MCP enabled and `{app.url}/v1/oauth/callback` as a
 * redirect URL.
 *
 * The token is a user token (`oauth.v2.user.access`): the agent reads and posts as whoever signed in. This is
 * unrelated to the agent's own Slack channel (bot token under `AgentChannelTokenEnum`).
 *
 * Scopes are exactly what the resource advertises — the server's tools are search, read, post and canvases,
 * and a narrower set leaves tools listed that fail on use.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'slack_mcp';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => self::NAME,
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Slack',
                'url' => 'https://mcp.slack.com/mcp',
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => 'slack',
                'exclude' => [],
                'timeout_ms' => 30000,
                'oauth' => [
                    'client_key' => 'slack',
                    'scopes' => [
                        'canvases:read',
                        'canvases:write',
                        'channels:history',
                        'channels:read',
                        'channels:write',
                        'chat:write',
                        'emoji:read',
                        'files:read',
                        'files:write',
                        'groups:history',
                        'groups:read',
                        'groups:write',
                        'im:history',
                        'im:read',
                        'im:write',
                        'lists:read',
                        'lists:write',
                        'mpim:history',
                        'mpim:read',
                        'mpim:write',
                        'reactions:read',
                        'reactions:write',
                        'search:read.files',
                        'search:read.im',
                        'search:read.mpim',
                        'search:read.private',
                        'search:read.public',
                        'search:read.users',
                        'users:read',
                        'users:read.email',
                    ],
                ],
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->delete();
    }
};
