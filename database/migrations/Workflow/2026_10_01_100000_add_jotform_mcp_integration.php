<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Jotform's hosted server — forms and submissions.
 *
 * Probed 2026-10-01: the 401 carries no `WWW-Authenticate`, but both well-known protected-resource
 * documents exist and point at `oauth2.jotform.com`, whose registration endpoint
 * (`/register-public-client`) accepted `{app.url}/v1/oauth/callback` as a public client — one click, no
 * hand-made client, no pinned authorization server.
 *
 * OAuth only: a bearer that isn't a Jotform-issued JWT gets `INVALID_JWT`, so a plain API key is never read.
 * The resource advertises just `readOnly` and `full`; `full` is a deliberate choice so agents can build and
 * edit forms, not only read submissions.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'jotform_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'jotform_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Jotform',
                'transport' => 'http',
                'url' => 'https://mcp.jotform.com/mcp',
                'auth_methods' => ['oauth'],
                'oauth' => [
                    'scopes' => ['full'],
                ],
                'prefix' => 'jotform',
                'exclude' => [],
                'timeout_ms' => 20000,
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
            ->where('name', 'jotform_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
