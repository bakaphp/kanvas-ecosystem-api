<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Typesense Cloud's hosted server: the account's clusters (config, status, metrics, parameters) and the
 * data inside them (collections, documents, search, synonyms, curation, presets, stopwords, analytics
 * rules, models), plus a docs search matched to the cluster's version.
 *
 * Probed 2026-10-13: `initialize` → 401 naming the path-aware protected-resource document → authorization
 * server `https://cloud.typesense.org`, RFC 8414 at the origin root (the OIDC placement 404s). Public
 * clients (`none`), PKCE S256, refresh tokens, resource indicators, and `/oauth/register` accepted
 * `{app.url}/v1/oauth/callback` first try — one click, no hand-made client, no pinned server.
 *
 * Scopes are the vendor's "Build" set as its 401 challenge advertises it, minus `clusters:create` and
 * `clusters:configure`: creating or resizing anything beyond the free tier bills the account's payment
 * method, and spend is not something an agent gets by connecting. Tuning parameters and everything
 * inside a cluster stay. Widening to the two cluster scopes is deliberate, not a fix. `admin`,
 * `clusters:terminate`, `clusters:clone`, `cluster_credentials:*` and `billing:*` are never asked for.
 *
 * The bearer alternative is a Cluster Management API key: it unlocks the cluster tools only (`whoami`,
 * `search_typesense_docs`, `get_clusters`, `manage_cluster`), never the data ones.
 *
 * `submit_feedback` is excluded: it reports to Typesense's team on the person's behalf, which is not
 * the agent's call. `documents` stays although large imports and every export come back as a curl
 * command meant for a person's machine — the catalog description tells the agent to relay it, not
 * claim to have run it.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'typesense_cloud_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'typesense_cloud_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Typesense Cloud',
                'transport' => 'http',
                'url' => 'https://cloud.typesense.org/mcp/v1',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'oauth' => [
                    'scopes' => [
                        'clusters:read',
                        'metrics:read',
                        'cluster_parameters:manage',
                        'data:view',
                        'data:search',
                        'data:full_access',
                        'data:manage_synonyms',
                        'data:manage_overrides',
                        'data:manage_presets',
                        'data:manage_stopwords',
                        'data:manage_stemming',
                        'data:manage_analytics_rules',
                        'data:manage_conversation_models',
                        'data:manage_natural_language_models',
                    ],
                ],
                'prefix' => 'typesense',
                'exclude' => ['submit_feedback'],
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
            ->where('name', 'typesense_cloud_mcp')
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
