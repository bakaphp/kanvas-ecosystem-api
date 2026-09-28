<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Kernel's hosted server — cloud Chromium for agents: browsers, Playwright execution, video replays,
 * persistent profiles, proxies, and page-level tools via `webmcp`.
 *
 * Probed 2026-09-20, and it is the textbook flow for once: the 401 names
 * `oauth-protected-resource/mcp`, which points at `auth.onkernel.com` (Clerk-backed), whose metadata
 * advertises a registration endpoint — and it accepted `{app.url}/v1/oauth/callback` on the first try, so
 * connecting is one click with no hand-made client.
 *
 * **Do not add `offline_access`.** The Vercel row needs it for a refresh token, but here the resource
 * advertises `openid` alone and anything else comes back `invalid_scope: Requested scope is not
 * registered`. No scope parameter at all is accepted, which is what our provider sends by default, so the
 * row needs no `oauth` block. Whether a refresh token is issued without that scope is unverified — the
 * client is registered for the grant, but if none arrives the agent reconnects when the token expires.
 *
 * Authorizing redirects to an org picker (`dashboard.onkernel.com/oauth/select-org`), so the token an
 * agent ends up with belongs to whichever Kernel org the person picks.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'kernel_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'kernel_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Kernel',
                'transport' => 'http',
                'url' => 'https://mcp.onkernel.com/mcp',
                'auth_methods' => ['oauth'],
                'prefix' => 'kernel',
                'exclude' => [],
                'tool_arguments' => [
                    'manage_browsers' => [
                        // Chromium blocks automated downloads: WISE's export came back as a row of
                        // "Needs permission to download" against UUID-named files. Both keys are needed —
                        // the restriction lifts the block, the popups setting stops the prompt. Kernel
                        // applies them on `create` and ignores them on the tool's other actions (checked).
                        'chrome_policy' => [
                            'DownloadRestrictions' => 0,
                            'DefaultPopupsSetting' => 1,
                        ],
                        // Their default is 60s of inactivity, which kills a session while a person is
                        // still reading the live view — and takes its files with it.
                        'timeout_seconds' => 900,
                    ],
                ],
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
            ->where('name', 'kernel_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
