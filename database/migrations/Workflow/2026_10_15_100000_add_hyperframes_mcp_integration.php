<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * HeyGen's hosted HyperFrames server — HTML-defined video compositions the agent creates, edits and
 * renders (MP4/WebM/MOV) on HeyGen's infrastructure, no local toolchain.
 *
 * Probed 2026-10-15: `initialize` → 401 naming the path-aware protected-resource document → authorization
 * server `https://api2.heygen.com`, RFC 8414 at the origin root. Public clients (`none`), PKCE S256,
 * refresh tokens, resource indicators, `query` response mode, and `/v1/oauth/register` accepted
 * `{app.url}/v1/oauth/callback` first try — one click, no hand-made client, no pinned server. The vendor
 * renames the client "HeyGen MCP Default" and grants `openid` alone, which is also the only scope the
 * resource advertises that means anything (`profile`/`email` are claims, `offline_access` is not
 * advertised at all — do not add it, see the Kernel row). The URL must keep its trailing slash: the bare
 * path 307s and the transport refuses redirects by design.
 *
 * Two things only a live consent can settle: the resource names itself without the trailing slash while
 * we send the connection URL as `resource` — `/authorize` passes either through unchecked, so if the
 * token step answers `invalid_target`, set `include_resource: false` in the `oauth` block (the Amplitude
 * precedent); and whether a refresh token is issued without `offline_access`. The tool list is unprobed
 * (it needs a token). Rendering is the obvious background-job candidate — "keep the render identifier"
 * is the vendor's own wording — so read the first real `tools/list` for a render/status pair and declare
 * it under `async_jobs` before agents lean on it, or Neuron's per-turn run cap kills the turn mid-poll.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'hyperframes_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'hyperframes_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'HeyGen',
                'transport' => 'http',
                'url' => 'https://mcp.heygen.com/mcp/hyperframes/',
                'auth_methods' => ['oauth'],
                'oauth' => [
                    'scopes' => ['openid'],
                ],
                'prefix' => 'hyperframes',
                'exclude' => [],
                'timeout_ms' => 60000,
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
            ->where('name', 'hyperframes_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
