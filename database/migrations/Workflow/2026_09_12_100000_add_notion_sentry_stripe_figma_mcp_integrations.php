<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Four first-party remote MCP servers, all of which DO support dynamic client registration — unlike
 * Google's, they need no hand-made OAuth client and no app settings, so connecting is one click.
 *
 * Scopes are the ones each server advertises in its own RFC 8414 metadata; Figma refuses a request
 * without `mcp:connect`. Stripe additionally accepts a restricted API key as a bearer token, which is
 * why it is the only one of the four offering both methods.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'notion_mcp' => [
            'vendor' => 'Notion',
            'url' => 'https://mcp.notion.com/mcp',
            'prefix' => 'notion',
            'auth_methods' => ['oauth'],
            'scopes' => ['default'],
        ],
        'sentry_mcp' => [
            'vendor' => 'Sentry',
            'url' => 'https://mcp.sentry.dev/mcp',
            'prefix' => 'sentry',
            'auth_methods' => ['oauth'],
            'scopes' => ['org:read', 'project:write', 'team:write', 'event:write'],
        ],
        'stripe_mcp' => [
            'vendor' => 'Stripe',
            'url' => 'https://mcp.stripe.com',
            'prefix' => 'stripe',
            'auth_methods' => ['bearer', 'oauth'],
            'scopes' => ['mcp'],
        ],
        'figma_mcp' => [
            'vendor' => 'Figma',
            'url' => 'https://mcp.figma.com/mcp',
            'prefix' => 'figma',
            'auth_methods' => ['oauth'],
            'scopes' => ['mcp:connect'],
        ],
    ];

    public function up(): void
    {
        foreach (self::SERVERS as $name => $server) {
            $exists = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('workflow')->table('integrations')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'handler' => McpHandler::class,
                'type' => IntegrationTypeEnum::MCP->value,
                'apps_id' => 0,
                'config' => json_encode([]),
                'metadata' => json_encode([
                    'vendor' => $server['vendor'],
                    'url' => $server['url'],
                    'transport' => 'http',
                    'auth_methods' => $server['auth_methods'],
                    'prefix' => $server['prefix'],
                    'exclude' => [],
                    'timeout_ms' => 20000,
                    'oauth' => [
                        'scopes' => $server['scopes'],
                    ],
                ]),
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->whereIn('name', array_keys(self::SERVERS))
            ->where('apps_id', 0)
            ->delete();
    }
};
