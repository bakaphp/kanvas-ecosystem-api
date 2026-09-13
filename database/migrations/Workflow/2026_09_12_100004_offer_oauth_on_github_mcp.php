<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GitHub's remote MCP server offers OAuth as well as a personal access token, so an agent can sign in as
 * its own GitHub account instead of holding a pasted token.
 *
 * Like Google and unlike Notion/Sentry/Stripe/Figma, GitHub does NO dynamic client registration: an admin
 * creates one OAuth app (callback `/v1/oauth/callback`) and stores it as the app settings
 * `mcp_oauth_client_id_github` / `mcp_oauth_client_secret_github`, shared through `client_key`. Until
 * that exists the OAuth button answers with a validation error naming the missing setting, while the
 * existing bearer path keeps working untouched.
 *
 * Scopes are the ones the server's own RFC 9728 metadata advertises, minus the write-heavy ones nobody
 * has asked for yet (`write:packages`, `project`, `gist`).
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array OAUTH = [
        'client_key' => 'github',
        'scopes' => [
            'repo',
            'read:org',
            'read:user',
            'user:email',
            'read:project',
            'notifications',
        ],
    ];

    public function up(): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'github_mcp')
            ->where('apps_id', 0)
            ->first();

        if ($row === null) {
            return;
        }

        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        $metadata['auth_methods'] = ['bearer', 'oauth'];
        $metadata['oauth'] = self::OAUTH;

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'github_mcp')
            ->where('apps_id', 0)
            ->first();

        if ($row === null) {
            return;
        }

        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        $metadata['auth_methods'] = ['bearer'];
        unset($metadata['oauth']);

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }
};
