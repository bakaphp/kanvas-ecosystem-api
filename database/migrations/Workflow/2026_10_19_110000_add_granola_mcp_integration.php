<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Granola's hosted server — the signed-in person's meeting notes, transcripts and folders.
 *
 * Probed 2026-10-19: `initialize` → 401 naming the origin-level protected-resource document (the
 * path-aware one answers the same) → authorization server `https://mcp-auth.granola.ai`, RFC 8414 at the
 * origin root. Public clients (`none`), PKCE S256, refresh tokens, `query` response mode, and
 * `/oauth2/register` accepted `{app.url}/v1/oauth/callback` first try — one click, no hand-made client,
 * no pinned server. `/authorize` validates scopes (a bogus one comes back `invalid_scope`) and accepts
 * `mcp` — the only scope the resource advertises — plus the authorization server's `offline_access`,
 * requested so the grant survives the first expiry.
 *
 * The token reads the person's active Granola workspace only, and Granola's Basic plan limits it to the
 * last 30 days of their own notes; folder, search and transcript tools need a paid plan. Tool list
 * unprobed (it needs a token) — every published tool reads, so nothing is excluded yet.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'granola_mcp';

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
                'vendor' => 'Granola',
                'transport' => 'http',
                'url' => 'https://mcp.granola.ai/mcp',
                'auth_methods' => ['oauth'],
                'oauth' => [
                    'scopes' => [
                        'mcp',
                        'offline_access',
                    ],
                ],
                'prefix' => 'granola',
                'exclude' => [],
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
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->delete();
    }
};
