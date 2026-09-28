<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Vercel's official server: docs search, teams/projects, deployments with build and runtime logs,
 * Web Analytics, Agent Runs traces and the deployment-comment toolbar.
 *
 * OAuth only. An opaque token is not an option — a Vercel access token in the Authorization header
 * comes back `401 invalid_token, "No authorization provided"`, i.e. the server never even reads it
 * as a credential, so there is no bearer fallback the way Atlassian has one.
 *
 * Probed 2026-09-17: discovery is clean (`initialize` → 401 naming resource_metadata → resource points
 * at `https://vercel.com` → RFC 8414 path-inserted answers), so no `authorization_server` pin. The
 * registration endpoint it advertises works, but only for redirect URIs Vercel has approved: our
 * `{app.url}/v1/oauth/callback` is refused with `invalid_redirect_uri` ("The provided redirect URIs are
 * not approved for use by this authorization server"), while `https://claude.ai/api/mcp/auth_callback`
 * and `http://localhost` register fine. That allow-list is how the docs' "only supports AI clients
 * reviewed and approved by Vercel" is enforced. Hence `client_key: vercel` — a hand-made client set on
 * the app, never self-registration. The catalog row lands inactive until one exists.
 *
 * `offline_access` is requested on top of the `openid` the resource advertises because without it the
 * token endpoint returns no refresh token and every agent's grant dies at the first expiry.
 *
 * Excluded on purpose:
 *  - the whole purchase family — charges are immediate and non-refundable, and Vercel's only guard is
 *    the MCP *client* prompting on `confirm: true`. An agent turn has no such prompt, so quoting is
 *    excluded alongside the buys (it is the required first step, useless without them);
 *  - `get_access_to_vercel_url` — mints a link that bypasses deployment protection, and an agent that
 *    pastes one into a channel has handed a protected preview to everyone who can read it;
 *  - `deploy_to_vercel` — creates the project if absent and accepts `target: production`; shipping to
 *    production from inside a turn needs a deliberate decision, not a default;
 *  - `import-claude-design-from-url` — wants a ~1h `claudeusercontent.com` bundle and deploys it;
 *  - `use_vercel_cli` — returns "run this CLI command" advice to an agent that has no shell, which
 *    invites it to report work it never did.
 *
 * `web_fetch_vercel_url` stays: it is the "does my page render" case, and it reads only the team's own
 * deployments — though what it returns is page content, so it carries the usual prompt-injection risk.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'vercel_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'vercel_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Vercel',
                'url' => 'https://mcp.vercel.com',
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => 'vercel',
                'exclude' => [
                    'get_purchase_quote',
                    'buy_pro',
                    'buy_credits',
                    'buy_addon',
                    'buy_domain',
                    'get_domain_order',
                    'get_access_to_vercel_url',
                    'deploy_to_vercel',
                    'import-claude-design-from-url',
                    'use_vercel_cli',
                ],
                'oauth' => [
                    'client_key' => 'vercel',
                    'scopes' => [
                        'openid',
                        'offline_access',
                    ],
                ],
                'timeout_ms' => 30000,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'vercel_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
