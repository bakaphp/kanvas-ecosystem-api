<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Higgsfield's image and video generation, connected in one click: its authorization server is Clerk,
 * which registers clients dynamically (`clerk.higgsfield.ai/oauth/register`, PKCE S256, refresh tokens).
 *
 * The address is `mcp.higgsfield.ai`, NOT the `higgsfield.ai/mcp` people share: that one 307-redirects,
 * and this transport refuses redirects on purpose — an SSRF guard that follows them is not a guard — so a
 * row pointing there would fail every call.
 *
 * The server offers two flows and picks by client capability. Kanvas takes the authorization-code one: it
 * has a redirect receiver (`/v1/oauth/callback`) and does PKCE. The device-code alternative exists for
 * clients that cannot receive a redirect at all, and needs no support here.
 *
 * Scopes are exactly the three the resource asks for, not the wider set Clerk itself advertises —
 * requesting scopes a resource never asked for is how a consent screen starts refusing.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'higgsfield_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'higgsfield_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Higgsfield',
                'url' => 'https://mcp.higgsfield.ai/mcp',
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => 'higgsfield',
                'exclude' => [],
                // Generating a video is not a fast call.
                'timeout_ms' => 60000,
                'oauth' => [
                    'scopes' => ['openid', 'email', 'offline_access'],
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
            ->where('name', 'higgsfield_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
