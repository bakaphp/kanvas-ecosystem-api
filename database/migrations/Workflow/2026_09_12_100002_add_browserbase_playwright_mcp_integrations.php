<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Two browser-automation servers, both connected by address alone (`none`).
 *
 * Browserbase is hosted but takes its API key in the query string rather than a header, so the URL the
 * admin pastes IS the credential: `https://mcp.browserbase.com/mcp?browserbaseApiKey=...`. It also
 * answers `tools/list` without a valid key, so connecting cannot prove the key — a wrong one first shows
 * up when a browser is actually opened. Playwright publishes no hosted server at all; a company runs
 * `npx @playwright/mcp --port` itself, which is why it takes an address per connection like n8n, and may
 * put a bearer token in front of it.
 *
 * The timeout is generous on purpose: a tool call here drives a real browser.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'browserbase_mcp' => [
            'vendor' => 'Browserbase',
            'prefix' => 'browserbase',
            'auth_methods' => ['none'],
        ],
        'playwright_mcp' => [
            'vendor' => 'Playwright',
            'prefix' => 'playwright',
            'auth_methods' => ['none', 'bearer'],
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
                    'transport' => 'http',
                    'auth_methods' => $server['auth_methods'],
                    'prefix' => $server['prefix'],
                    'exclude' => [],
                    'timeout_ms' => 60000,
                    'url_per_connection' => true,
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
